<?php

namespace SnapshotBackup\Services;

use Illuminate\Support\Facades\Log;
use SnapshotBackup\Models\BackupSnapshot;
use SnapshotBackup\Services\MediaService;
use Symfony\Component\Process\Process;

class SnapshotService
{
    private array $config;

    public function __construct()
    {
        $this->config = config('snapshot-backup');
    }

    /**
     * Run rsync file snapshot to ALL configured SFTP disks.
     * Non-SFTP disks are skipped with a warning (rsync requires SSH).
     * Fails if no SFTP disk succeeds.
     */
    public function runFileBackup(?string $serverId = null, ?string $appName = null): BackupSnapshot
    {
        $serverId ??= $this->config['server_id'];
        $appName  ??= $this->config['app_name'];

        $currSlot  = now()->format('Y-m-d_H0000');
        $currDate  = substr($currSlot, 0, 10);
        $startedAt = now();

        $record = BackupSnapshot::create([
            'server_id'     => $serverId,
            'app_name'      => $appName,
            'type'          => 'files',
            'snapshot_date' => $currDate,
            'snapshot_slot' => $currSlot,
            'status'        => 'running',
        ]);

        $succeeded = [];
        $failed    = [];

        try {
            foreach ($this->config['disks'] as $diskName) {
                $diskConfig = config("filesystems.disks.{$diskName}");

                if (($diskConfig['driver'] ?? '') !== 'sftp') {
                    Log::channel('backup')->warning(
                        "Skipping disk:{$diskName} for file snapshot — driver '{$diskConfig['driver']}' is not sftp."
                    );
                    continue;
                }

                try {
                    $ssh         = $this->sshFromDiskConfig($diskName, $diskConfig);
                    $sizeBytes   = $this->runSnapshotOnDisk($ssh, $diskName, $serverId, $appName, $currSlot);
                    $succeeded[] = $diskName;
                } catch (\Throwable $e) {
                    $failed[] = "{$diskName}: " . $e->getMessage();
                    Log::channel('backup')->error("File backup FAILED disk:{$diskName} {$serverId}/{$appName}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (empty($succeeded)) {
                throw new \RuntimeException(
                    empty($failed)
                        ? 'No SFTP disks configured for file snapshots.'
                        : 'All SFTP disks failed: ' . implode(' | ', $failed)
                );
            }

            if (!empty($failed)) {
                throw new \RuntimeException('Some disks failed: ' . implode(' | ', $failed));
            }

            $record->update([
                'status'           => 'success',
                'size_bytes'       => $sizeBytes ?? null,
                'duration_seconds' => now()->diffInSeconds($startedAt),
                'rsync_stats'      => 'disks:' . implode(',', $succeeded),
            ]);

            Log::channel('backup')->info(
                "File backup SUCCESS: {$serverId}/{$appName} ({$currSlot}) disks=" . implode(',', $succeeded)
            );

        } catch (\Throwable $e) {
            $record->update([
                'status'           => 'failed',
                'error_message'    => $e->getMessage(),
                'duration_seconds' => now()->diffInSeconds($startedAt),
            ]);

            Log::channel('backup')->error("File backup FAILED: {$serverId}/{$appName}", ['error' => $e->getMessage()]);

            throw $e;
        }

        return $record->fresh();
    }

    private function runSnapshotOnDisk(
        array $ssh,
        string $diskName,
        string $serverId,
        string $appName,
        string $currSlot,
    ): ?int {
        $remoteBase    = ($ssh['root'] !== '' ? $ssh['root'] . '/' : '') . $serverId . '/' . $appName;
        $fileSlotsBase = $remoteBase . '/snapshots/files';
        $snapCurr      = $fileSlotsBase . '/' . $currSlot;

        // SFTP paths (Flysystem prepends disk root internally).
        $sftpBase          = $serverId . '/' . $appName;
        $sftpFileSlotsBase = $sftpBase . '/snapshots/files';
        $sftpSnapCurr      = $sftpFileSlotsBase . '/' . $currSlot;

        // Ensure destination slot directory exists (mkdir -p tolerates re-runs).
        // --mkpath was dropped; it requires rsync ≥ 3.2.3 which is not available everywhere.
        $this->remoteExec($ssh, "mkdir -p {$snapCurr}", false);
        // Unlock in case the slot already exists from a same-slot re-run (non-fatal).
        $this->remoteExec($ssh, "chmod -R u+w {$snapCurr}", false);

        // Find the most recent slot before this one → --link-dest source.
        $prevSlot = collect(MediaService::disk($diskName)->directories($sftpFileSlotsBase))
            ->map(fn ($d) => basename($d))
            ->filter(fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $d) && $d < $currSlot)
            ->sort()
            ->last();

        $linkDestArg = $prevSlot ? "../{$prevSlot}/" : null;

        if ($prevSlot) {
            Log::channel('backup')->info("disk:{$diskName} --link-dest from: {$prevSlot}");
        } else {
            Log::channel('backup')->warning("disk:{$diskName} no previous file slot — full backup.");
        }

        $includes = $this->config['source']['files']['include'];
        $excludes = $this->config['source']['files']['exclude'];

        try {
            foreach ($includes as $includePath) {
                $rsyncCmd = $this->buildRsyncCommand(
                    srcPath:  rtrim($includePath, '/'),
                    ssh:      $ssh,
                    destPath: $snapCurr . '/',
                    linkDest: $linkDestArg,
                    excludes: $excludes,
                );
                $this->runWithRetry($rsyncCmd, $this->config['rsync']['retry_count'], $this->config['rsync']['retry_delay']);
            }

            // Disk sources (S3, GCS, etc.) — stream-copied via Flysystem.
            // Each disk lands in SLOT/{diskname}/ on the backup disk.
            foreach ($this->config['source']['files']['disks'] ?? [] as $sourceDiskName) {
                $this->backupDiskSource(
                    sourceDiskName: $sourceDiskName,
                    backupDiskName: $diskName,
                    ssh:            $ssh,
                    remoteSnapCurr: $snapCurr,
                    sftpSnapCurr:   $sftpSnapCurr,
                );
            }

            // Update latest symlink (non-fatal — restricted shells may ignore ln).
            $this->remoteExec($ssh, "ln -sfn snapshots/files/{$currSlot} {$remoteBase}/latest", false);
            // Lock slot read-only (non-fatal).
            $this->remoteExec($ssh, "chmod -R a-w {$snapCurr}", false);
            // Bootstrap the DB backup directory via SSH (reliable on Hetzner restricted shell).
            $this->remoteExec($ssh, "mkdir -p {$remoteBase}/snapshots/db", false);

        } catch (\Throwable $e) {
            try { MediaService::disk($diskName)->deleteDirectory($sftpSnapCurr); } catch (\Throwable) {}
            throw $e;
        }

        return $this->parseSnapshotSize($ssh, $snapCurr);
    }

    // ── SSH / rsync Helpers ───────────────────────────────────────────────────

    private function sshFromDiskConfig(string $diskName, array $diskConfig): array
    {
        $hasKey      = !empty($diskConfig['privateKey']);
        $hasPassword = !empty($diskConfig['password']);

        if (!$hasKey && !$hasPassword) {
            throw new \RuntimeException(
                "Disk '{$diskName}' has neither 'privateKey' nor 'password' configured."
            );
        }

        if ($hasPassword && !$hasKey) {
            $check = new Process(['which', 'sshpass']);
            $check->run();
            if (!$check->isSuccessful()) {
                throw new \RuntimeException(
                    "Disk '{$diskName}' uses password auth but 'sshpass' is not installed. "
                    . "Install it (apt install sshpass) or switch to SSH key auth."
                );
            }
        }

        return [
            'host'     => $diskConfig['host'],
            'port'     => (int) ($diskConfig['port'] ?? 22),
            'user'     => $diskConfig['username'],
            'ssh_key'  => $diskConfig['privateKey'] ?? null,
            'password' => $diskConfig['password'] ?? null,
            'root'     => rtrim($diskConfig['root'] ?? '/backups', '/'),
        ];
    }

    private function buildRsyncCommand(
        string $srcPath,
        array $ssh,
        string $destPath,
        ?string $linkDest,
        array $excludes,
    ): array {
        $sshCmd = $this->buildSshInlineCmd($ssh);

        $cmd = [];

        if ($ssh['password'] !== null && $ssh['ssh_key'] === null) {
            array_push($cmd, 'sshpass', '-p', $ssh['password']);
        }

        array_push($cmd,
            'rsync',
            '--archive',
            '--compress',
            '--human-readable',
            '--delete',
            '--delete-excluded',
            '--stats',
            '-e', $sshCmd,
        );

        if ($linkDest !== null) {
            $cmd[] = '--link-dest=' . $linkDest;
        }

        foreach ($excludes as $pattern) {
            $cmd[] = '--exclude=' . $pattern;
        }

        $cmd[] = $srcPath;
        $cmd[] = "{$ssh['user']}@{$ssh['host']}:{$destPath}";

        return $cmd;
    }

    private function buildSshInlineCmd(array $ssh): string
    {
        $useKey = $ssh['ssh_key'] !== null;

        $parts = ['ssh', '-p', $ssh['port'], '-o', 'StrictHostKeyChecking=accept-new',
                  '-o', 'ConnectTimeout=' . $this->config['rsync']['ssh_timeout'],
                  '-o', 'BatchMode=' . ($useKey ? 'yes' : 'no')];

        if ($useKey) {
            array_splice($parts, 1, 0, ['-i', $ssh['ssh_key']]);
        }

        return implode(' ', $parts);
    }

    private function runWithRetry(array $cmd, int $retries, int $delaySeconds): void
    {
        $attempt   = 0;
        $lastError = null;

        while ($attempt < $retries) {
            $attempt++;
            Log::channel('backup')->info("rsync attempt {$attempt}/{$retries}");

            $process = new Process($cmd, timeout: $this->config['queue']['timeout']);
            $process->run();

            $exitCode = $process->getExitCode();

            // 0 = success, 24 = vanished source files (acceptable)
            if ($exitCode === 0 || $exitCode === 24) {
                Log::channel('backup')->info("rsync completed.\n" . $this->extractStats($process->getOutput()));
                return;
            }

            $lastError = "exit {$exitCode}: " . trim($process->getErrorOutput());
            Log::channel('backup')->warning("rsync failed (attempt {$attempt}): {$lastError}");

            if ($attempt < $retries) {
                sleep($delaySeconds);
            }
        }

        throw new \RuntimeException("rsync failed after {$retries} attempts. Last: {$lastError}");
    }

    private function remoteExec(array $ssh, string $command, bool $throwOnFailure = true): string
    {
        $useKey = $ssh['ssh_key'] !== null;

        $sshArgs = $useKey
            ? ['-i', $ssh['ssh_key'], '-p', $ssh['port'], '-o', 'StrictHostKeyChecking=accept-new',
               '-o', 'ConnectTimeout=' . $this->config['rsync']['ssh_timeout'], '-o', 'BatchMode=yes']
            : ['-p', $ssh['port'], '-o', 'StrictHostKeyChecking=accept-new',
               '-o', 'ConnectTimeout=' . $this->config['rsync']['ssh_timeout'], '-o', 'BatchMode=no'];

        $cmd = $useKey
            ? array_merge(['ssh'], $sshArgs, [$ssh['user'] . '@' . $ssh['host'], $command])
            : array_merge(['sshpass', '-p', $ssh['password'], 'ssh'], $sshArgs, [$ssh['user'] . '@' . $ssh['host'], $command]);

        $process = new Process($cmd, timeout: 60);
        $process->run();

        if ($throwOnFailure && !$process->isSuccessful()) {
            throw new \RuntimeException("Remote command failed: {$command}\n" . $process->getErrorOutput());
        }

        return $process->getOutput();
    }

    /**
     * Stream-copy all files from a Laravel filesystem disk (e.g. S3) into
     * SLOT/{sourceDiskName}/ on the backup disk.
     *
     * No --link-dest deduplication — each slot is a full copy of the source disk.
     * SSH mkdir -p is called first to ensure the base dir exists on Hetzner before
     * Flysystem writes (avoids the silent-fail on freshly created SFTP paths).
     */
    private function backupDiskSource(
        string $sourceDiskName,
        string $backupDiskName,
        array $ssh,
        string $remoteSnapCurr,  // SSH path used for mkdir
        string $sftpSnapCurr,    // Flysystem path (disk root prepended internally)
    ): void {
        $sourceDisk   = MediaService::disk($sourceDiskName);
        $backupDisk   = MediaService::disk($backupDiskName);
        $sftpDestBase = $sftpSnapCurr . '/' . $sourceDiskName;

        // Ensure the base destination dir exists via SSH before any Flysystem write.
        $this->remoteExec($ssh, "mkdir -p {$remoteSnapCurr}/{$sourceDiskName}", false);

        $files  = $sourceDisk->allFiles();
        $copied = 0;
        $errors = 0;

        foreach ($files as $file) {
            try {
                $stream = $sourceDisk->readStream($file);
                if (!is_resource($stream)) {
                    continue;
                }
                $backupDisk->writeStream("{$sftpDestBase}/{$file}", $stream);
                fclose($stream);
                $copied++;
            } catch (\Throwable $e) {
                $errors++;
                Log::channel('backup')->warning(
                    "disk-source backup: failed to copy '{$file}' from {$sourceDiskName}: " . $e->getMessage()
                );
            }
        }

        Log::channel('backup')->info(
            "disk-source backup: {$sourceDiskName} → {$backupDiskName}/{$sourceDiskName}"
            . " copied={$copied} errors={$errors}"
        );

        if ($errors > 0 && $copied === 0) {
            throw new \RuntimeException(
                "disk-source backup failed: no files copied from {$sourceDiskName} ({$errors} errors)"
            );
        }
    }

    private function parseSnapshotSize(array $ssh, string $remotePath): ?int
    {
        try {
            $out = $this->remoteExec($ssh, "du -sb {$remotePath}", false);
            return (int) trim(explode("\t", $out)[0]) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractStats(string $rsyncOutput): string
    {
        preg_match('/Number of files transferred: \d+.*?Total transferred file size: [\d,]+ bytes/s', $rsyncOutput, $m);
        return $m[0] ?? $rsyncOutput;
    }
}
