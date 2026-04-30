<?php

namespace SnapshotBackup\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
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
     * Run file snapshot to ALL configured SFTP disks.
     *
     * Per-disk backend selection via filesystems.php disk config:
     *   'snapshot_backend' => 'borg'   → Borg Backup (deduplication, recommended for Hetzner)
     *   'snapshot_backend' => 'rsync'  → plain rsync, no deduplication (default)
     *
     * Disk sources (S3, GCS, etc.) are always backed up via Flysystem stream-copy
     * into snapshots/disk-sources/{diskname}/{slot}/ — independent of the file backend.
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

        $succeeded  = [];
        $failed     = [];
        $sizeBytes  = null;
        $fileCount  = 0;

        try {
            foreach ($this->config['disks'] as $diskName) {
                $diskConfig = config("filesystems.disks.{$diskName}");

                if (($diskConfig['driver'] ?? '') !== 'sftp') {
                    Log::channel('backup')->warning(
                        "Skipping disk:{$diskName} for file snapshot — driver '{$diskConfig['driver']}' is not sftp."
                    );
                    continue;
                }

                $backend = $diskConfig['snapshot_backend'] ?? 'rsync';

                try {
                    $ssh = $this->sshFromDiskConfig($diskName, $diskConfig);

                    if ($backend === 'borg') {
                        $sizeBytes = $this->runBorgBackup($ssh, $diskName, $currSlot);
                    } else {
                        $sizeBytes = $this->runRsyncSnapshot($ssh, $diskName, $currSlot);
                    }

                    // Disk sources (S3, GCS, etc.) — always via Flysystem, separate from file backend.
                    foreach ($this->config['source']['files']['disks'] ?? [] as $sourceDiskName) {
                        $fileCount += $this->backupDiskSource($sourceDiskName, $diskName, $ssh, $currSlot);
                    }

                    // Count local include paths for borg/rsync file count.
                    foreach ($this->config['source']['files']['include'] ?? [] as $path) {
                        if (is_dir($path)) {
                            $fileCount += iterator_count(
                                new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS))
                            );
                        }
                    }

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

            // Detect anomalies by comparing against the last successful backup.
            $status = $this->detectBackupAnomaly($serverId, $appName, $fileCount);

            $record->update([
                'status'           => $status,
                'size_bytes'       => $sizeBytes,
                'file_count'       => $fileCount,
                'duration_seconds' => now()->diffInSeconds($startedAt),
                'rsync_stats'      => 'disks:' . implode(',', $succeeded),
            ]);

            if ($status === 'empty') {
                Log::channel('backup')->error(
                    "File backup EMPTY: {$serverId}/{$appName} ({$currSlot}) — source has 0 files!"
                );
                $this->sendAnomalyAlert($serverId, $appName, $fileCount, 0);
            } elseif ($status === 'warning') {
                $prevCount = $this->getLastSuccessfulFileCount($serverId, $appName);
                Log::channel('backup')->warning(
                    "File backup WARNING: {$serverId}/{$appName} ({$currSlot})"
                    . " — file count dropped from {$prevCount} to {$fileCount} (>50% decrease)"
                );
                $this->sendAnomalyAlert($serverId, $appName, $fileCount, $prevCount);
            } else {
                Log::channel('backup')->info(
                    "File backup SUCCESS: {$serverId}/{$appName} ({$currSlot})"
                    . " files={$fileCount} disks=" . implode(',', $succeeded)
                );
            }

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

    // ── Anomaly detection ─────────────────────────────────────────────────────

    /**
     * Compare current file count against the last successful backup.
     * Returns 'empty', 'warning', or 'success'.
     */
    private function detectBackupAnomaly(string $serverId, string $appName, int $fileCount): string
    {
        if ($fileCount === 0) {
            return 'empty';
        }

        $prevCount = $this->getLastSuccessfulFileCount($serverId, $appName);

        // No previous backup to compare — first run is always success.
        if ($prevCount === null || $prevCount === 0) {
            return 'success';
        }

        // >50% drop is suspicious.
        if ($fileCount < $prevCount * 0.5) {
            return 'warning';
        }

        return 'success';
    }

    private function getLastSuccessfulFileCount(string $serverId, string $appName): ?int
    {
        return BackupSnapshot::query()
            ->forApp($serverId, $appName)
            ->where('type', 'files')
            ->where('status', 'success')
            ->whereNotNull('file_count')
            ->latest('id')
            ->value('file_count');
    }

    private function sendAnomalyAlert(string $serverId, string $appName, int $currentCount, ?int $previousCount): void
    {
        $emails = array_filter((array) config('snapshot-backup.notifications.mail'));

        if (empty($emails)) {
            return;
        }

        $message = $currentCount === 0
            ? "Source has 0 files — bucket/folder may have been deleted."
            : "File count dropped from {$previousCount} to {$currentCount} (>" . '50% decrease).';

        Notification::route('mail', $emails)
            ->notifyNow(new \SnapshotBackup\Notifications\SnapshotBackupFailed(
                $serverId,
                $appName,
                'files',
                new \RuntimeException("Backup anomaly: {$message}")
            ));
    }

    // ── Borg backend ──────────────────────────────────────────────────────────

    private function runBorgBackup(
        array $ssh,
        string $diskName,
        string $currSlot,
    ): ?int {
        $includes   = $this->config['source']['files']['include'];
        $excludes   = $this->config['source']['files']['exclude'];
        $remotePath = $this->config['remote_path'];

        if (empty($includes)) {
            Log::channel('backup')->info(
                "borg create skipped disk:{$diskName} path:{$remotePath} — no local paths configured."
            );
            return null;
        }

        $repoUrl = $this->borgRepoUrl($ssh);
        $borgEnv = $this->borgEnv($ssh);

        // Borg cannot init a repo if the parent directory doesn't exist.
        // Create it via SSH before the first init attempt.
        $this->remoteExec($ssh, 'mkdir -p ' . escapeshellarg('./' . $remotePath), false);

        $this->borgInitIfNeeded($repoUrl, $borgEnv);

        // Delete any existing archive for this slot so a re-run always produces a fresh backup.
        $delete = new Process(['borg', 'delete', "{$repoUrl}::{$currSlot}"], null, $borgEnv, null, 120);
        $delete->run(); // non-fatal — archive simply may not exist yet

        $cmd = ['borg', 'create', '--stats', '--compression', 'lz4'];

        foreach ($excludes as $pattern) {
            array_push($cmd, '--exclude', $pattern);
        }

        $cmd[] = "{$repoUrl}::{$currSlot}";

        foreach ($includes as $path) {
            $cmd[] = rtrim($path, '/');
        }

        $process = new Process($cmd, null, $borgEnv, null, $this->config['queue']['timeout']);
        $process->run();

        $exitCode = $process->getExitCode();
        if ($exitCode === 1) {
            Log::channel('backup')->warning(
                "borg create warnings disk:{$diskName}: " . trim($process->getErrorOutput())
            );
        } elseif ($exitCode !== 0) {
            throw new \RuntimeException('borg create failed: ' . trim($process->getErrorOutput()));
        }

        Log::channel('backup')->info(
            "borg create SUCCESS disk:{$diskName} path:{$remotePath}::{$currSlot}\n"
            . $process->getOutput()
        );

        $this->remoteExec($ssh, 'mkdir -p ' . escapeshellarg("./{$remotePath}/snapshots/db"), false);

        return null;
    }

    private function borgInitIfNeeded(string $repoUrl, array $borgEnv): void
    {
        try {
            $info = new Process(['borg', 'info', $repoUrl], null, $borgEnv, null, $this->config['rsync']['ssh_timeout']);
            $info->run();

            if ($info->isSuccessful()) {
                return;
            }
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {
            // Storage server can be slow at night — treat timeout as "status unknown"
            // and fall through to borg init, which handles "already exists" gracefully.
            Log::channel('backup')->warning("borg info timed out for {$repoUrl} — attempting init.");
        }

        Log::channel('backup')->info("Initializing new Borg repo: {$repoUrl}");

        $init = new Process(['borg', 'init', '--encryption=none', $repoUrl], null, $borgEnv, null, 60);
        $init->run();

        if (!$init->isSuccessful()) {
            $stderr = trim($init->getErrorOutput());

            // "already exists" means borg info failed for a transient reason
            // (timeout, lock, etc.) but the repo is actually there — safe to continue.
            if (str_contains($stderr, 'already exists')) {
                Log::channel('backup')->info("Borg repo already exists (borg info had failed): {$repoUrl}");
                return;
            }

            throw new \RuntimeException('borg init failed: ' . $stderr);
        }

        Log::channel('backup')->info("Borg repo initialized.");
    }

    private function borgRepoUrl(array $ssh): string
    {
        $remotePath = $this->config['remote_path'];
        return "ssh://{$ssh['user']}@{$ssh['host']}:{$ssh['port']}/./{$remotePath}/borg-repo";
    }

    private function borgEnv(array $ssh): array
    {
        $sshParts = [
            'ssh',
            '-p', (string) $ssh['port'],
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'ConnectTimeout=' . $this->config['rsync']['ssh_timeout'],
        ];

        if ($ssh['ssh_key'] !== null) {
            array_splice($sshParts, 1, 0, ['-i', $ssh['ssh_key']]);
            array_push($sshParts, '-o', 'BatchMode=yes');
        } else {
            array_push($sshParts, '-o', 'BatchMode=no');
        }

        $sshCmd = implode(' ', $sshParts);

        if ($ssh['ssh_key'] === null && $ssh['password'] !== null) {
            $sshCmd = 'sshpass -p ' . escapeshellarg($ssh['password']) . ' ' . $sshCmd;
        }

        return [
            'BORG_RSH'        => $sshCmd,
            'BORG_PASSPHRASE' => '',
            'BORG_BASE_DIR'   => storage_path('app/snapshot-backup/borg'),
        ];
    }

    // ── rsync backend ─────────────────────────────────────────────────────────

    private function runRsyncSnapshot(
        array $ssh,
        string $diskName,
        string $currSlot,
    ): ?int {
        $remotePath   = $this->config['remote_path'];
        $remoteBase   = ($ssh['root'] !== '' ? $ssh['root'] . '/' : '') . $remotePath;
        $snapCurr     = $remoteBase . '/snapshots/files/' . $currSlot;
        $sftpSnapCurr = $remotePath . '/snapshots/files/' . $currSlot;

        $this->remoteExec($ssh, "mkdir -p {$snapCurr}", false);
        $this->remoteExec($ssh, "chmod -R u+w {$snapCurr}", false);

        $includes = $this->config['source']['files']['include'];
        $excludes = $this->config['source']['files']['exclude'];

        try {
            foreach ($includes as $includePath) {
                $rsyncCmd = $this->buildRsyncCommand(
                    srcPath:  rtrim($includePath, '/'),
                    ssh:      $ssh,
                    destPath: $snapCurr . '/',
                    excludes: $excludes,
                );
                $this->runWithRetry(
                    $rsyncCmd,
                    $this->config['rsync']['retry_count'],
                    $this->config['rsync']['retry_delay']
                );
            }

            $this->remoteExec($ssh, "ln -sfn snapshots/files/{$currSlot} {$remoteBase}/latest", false);
            $this->remoteExec($ssh, "chmod -R a-w {$snapCurr}", false);
            $this->remoteExec($ssh, "mkdir -p {$remoteBase}/snapshots/db", false);
        } catch (\Throwable $e) {
            try { MediaService::disk($diskName)->deleteDirectory($sftpSnapCurr); } catch (\Throwable) {}
            throw $e;
        }

        return $this->parseSnapshotSize($ssh, $snapCurr);
    }

    // ── Disk source backup (backend-agnostic, always Flysystem) ──────────────

    /**
     * @return int Number of files found in the source disk.
     */
    private function backupDiskSource(
        string $sourceDiskName,
        string $backupDiskName,
        array $ssh,
        string $currSlot,
    ): int {
        $remotePath    = $this->config['remote_path'];
        $sftpDestBase  = "{$remotePath}/snapshots/disk-sources/{$sourceDiskName}/{$currSlot}";
        $remoteDestDir = ($ssh['root'] !== '' ? $ssh['root'] . '/' : '') . $sftpDestBase;

        $sourceDisk = MediaService::disk($sourceDiskName);
        $backupDisk = MediaService::disk($backupDiskName);

        // Ensure the destination directory exists via SSH before any Flysystem write.
        $this->remoteExec($ssh, "mkdir -p {$remoteDestDir}", false);

        // Build an index of existing files in the previous slot (if any) for
        // incremental backup — skip files that haven't changed (same size).
        $prevSlotFiles = $this->getPreviousSlotIndex($backupDisk, $sourceDiskName, $currSlot);

        $files   = $sourceDisk->allFiles();
        $total   = count($files);
        $copied  = 0;
        $skipped = 0;
        $errors  = 0;

        Log::channel('backup')->info(
            "disk-source: starting {$sourceDiskName} → {$backupDiskName} slot:{$currSlot}"
            . " ({$total} files, " . count($prevSlotFiles) . " in previous slot)"
        );

        foreach ($files as $file) {
            try {
                // Skip if the file exists in the previous slot with the same size.
                if (isset($prevSlotFiles[$file])) {
                    $sourceSize = $sourceDisk->size($file);
                    if ($sourceSize === $prevSlotFiles[$file]) {
                        // Copy from previous slot on the same SFTP disk (server-side, no download).
                        $backupDisk->copy($prevSlotFiles['__prefix__'] . '/' . $file, "{$sftpDestBase}/{$file}");
                        $skipped++;

                        if ($skipped % 500 === 0) {
                            Log::channel('backup')->info(
                                "disk-source: {$sourceDiskName} carried forward: {$skipped}"
                            );
                        }
                        continue;
                    }
                }

                $stream = $sourceDisk->readStream($file);
                if (!is_resource($stream)) {
                    continue;
                }
                $backupDisk->writeStream("{$sftpDestBase}/{$file}", $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $copied++;

                if ($copied % 100 === 0) {
                    Log::channel('backup')->info(
                        "disk-source: {$sourceDiskName} progress: {$copied} copied, {$skipped} carried forward / {$total}"
                    );
                }
            } catch (\Throwable $e) {
                $errors++;
                Log::channel('backup')->warning(
                    "disk-source: failed to copy '{$file}' from {$sourceDiskName}: " . $e->getMessage()
                );
            }
        }

        Log::channel('backup')->info(
            "disk-source: {$sourceDiskName} → {$backupDiskName} slot:{$currSlot}"
            . " done: {$copied} copied, {$skipped} carried forward, {$errors} errors (total {$total})"
        );

        if ($errors > 0 && $copied === 0 && $skipped === 0) {
            throw new \RuntimeException(
                "disk-source backup failed: no files copied from {$sourceDiskName} ({$errors} errors)"
            );
        }

        return $total;
    }

    /**
     * Build a [relativePath => fileSize] index from the most recent previous slot
     * on the backup disk. Returns empty array if no previous slot exists.
     * The special key '__prefix__' holds the SFTP path prefix for server-side copy.
     */
    private function getPreviousSlotIndex(
        $backupDisk,
        string $sourceDiskName,
        string $currSlot,
    ): array {
        $remotePath      = $this->config['remote_path'];
        $diskSourcesBase = "{$remotePath}/snapshots/disk-sources/{$sourceDiskName}";

        try {
            $slots = collect($backupDisk->directories($diskSourcesBase))
                ->map(fn ($d) => basename($d))
                ->filter(fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $d) && $d < $currSlot)
                ->sort()
                ->values();

            if ($slots->isEmpty()) {
                return [];
            }

            $prevSlot  = $slots->last();
            $prevBase  = "{$diskSourcesBase}/{$prevSlot}";
            $prevFiles = $backupDisk->allFiles($prevBase);
            $prefixLen = strlen(rtrim($prevBase, '/')) + 1;

            $index = ['__prefix__' => $prevBase];
            foreach ($prevFiles as $filePath) {
                $relative = substr($filePath, $prefixLen);
                try {
                    $index[$relative] = $backupDisk->size($filePath);
                } catch (\Throwable) {
                    // Can't stat — skip, will be re-copied from source.
                }
            }

            Log::channel('backup')->info(
                "disk-source: previous slot {$prevSlot} indexed with " . (count($index) - 1) . " files"
            );

            return $index;
        } catch (\Throwable) {
            return [];
        }
    }

    // ── SSH / rsync helpers ───────────────────────────────────────────────────

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
            '--protect-args',   // pass remote path as-is; handles spaces in serverId/appName
            '-e', $sshCmd,
        );

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

    private function parseSnapshotSize(array $ssh, string $remotePath): ?int
    {
        try {
            $out = $this->remoteExec($ssh, "du -sb {$remotePath} 2>/dev/null | awk '{print $1}'", false);
            return (int) trim($out) ?: null;
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
