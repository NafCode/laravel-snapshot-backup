<?php

namespace SnapshotBackup\Services;

use Illuminate\Support\Facades\Log;
use SnapshotBackup\Models\BackupSnapshot;
use Symfony\Component\Process\Process;

class DatabaseDumpService
{
    private array $config;

    public function __construct()
    {
        $this->config = config('snapshot-backup');
    }

    /**
     * Dump the database once, then upload to ALL configured disks.
     * SFTP disks → rsync over SSH (reliable on Hetzner; Flysystem SFTP put()
     *              silently fails into freshly-created directories on first run).
     * Non-SFTP disks (s3, local, ftp, …) → Flysystem writeStream().
     */
    public function run(?string $serverId = null, ?string $appName = null): BackupSnapshot
    {
        $serverId ??= $this->config['server_id'];
        $appName  ??= $this->config['app_name'];
        $disks     = $this->config['disks'];

        $currDate  = now()->format('Y-m-d');
        $timeSlot  = now()->format('H') . '0000';
        $startedAt = now();

        $record = BackupSnapshot::create([
            'server_id'     => $serverId,
            'app_name'      => $appName,
            'type'          => 'database',
            'snapshot_date' => $currDate,
            'status'        => 'running',
        ]);

        $localDump = sys_get_temp_dir() . "/db-{$appName}-{$currDate}_{$timeSlot}.sql.gz";

        try {
            $this->dumpAndCompress($this->config['database'], $localDump);
            $this->verifyGzip($localDump);

            $sizeBytes  = filesize($localDump);
            $remotePath = "{$serverId}/{$appName}/snapshots/db/{$currDate}/" . basename($localDump);
            $failed     = [];

            foreach ($disks as $diskName) {
                $diskConfig = config("filesystems.disks.{$diskName}");
                try {
                    if (($diskConfig['driver'] ?? '') === 'sftp') {
                        $ssh       = $this->sshFromDiskConfig($diskName, $diskConfig);
                        $remoteDir = ($ssh['root'] !== '' ? $ssh['root'] . '/' : '')
                                   . "{$serverId}/{$appName}/snapshots/db/{$currDate}/";
                        $this->uploadViaRsync($localDump, $ssh, $remoteDir);
                    } else {
                        $stream = fopen($localDump, 'r');
                        MediaService::disk($diskName)->writeStream($remotePath, $stream);
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                    Log::channel('backup')->info("DB upload OK → disk:{$diskName} {$serverId}/{$appName} {$currDate}_{$timeSlot}");
                } catch (\Throwable $e) {
                    $failed[] = "{$diskName}: " . $e->getMessage();
                    Log::channel('backup')->error("DB upload FAILED → disk:{$diskName}", ['error' => $e->getMessage()]);
                }
            }

            if (!empty($failed)) {
                throw new \RuntimeException('Upload failed on disk(s): ' . implode(' | ', $failed));
            }

            $record->update([
                'status'           => 'success',
                'size_bytes'       => $sizeBytes,
                'duration_seconds' => now()->diffInSeconds($startedAt),
                'rsync_stats'      => 'disks:' . implode(',', $disks),
            ]);

            Log::channel('backup')->info(
                "DB backup SUCCESS: {$serverId}/{$appName} ({$currDate}_{$timeSlot})"
                . ' size=' . $this->humanBytes($sizeBytes)
                . ' disks=' . implode(',', $disks)
            );

        } catch (\Throwable $e) {
            $record->update([
                'status'           => 'failed',
                'error_message'    => $e->getMessage(),
                'duration_seconds' => now()->diffInSeconds($startedAt),
            ]);
            Log::channel('backup')->error("DB backup FAILED: {$serverId}/{$appName}", ['error' => $e->getMessage()]);
            throw $e;
        } finally {
            if (file_exists($localDump)) {
                unlink($localDump);
            }
        }

        return $record->fresh();
    }

    // ── Internal Helpers ──────────────────────────────────────────────────────

    private function uploadViaRsync(string $localFile, array $ssh, string $remoteDir): void
    {
        $sshCmd = $this->buildSshInlineCmd($ssh);

        $cmd = [];
        if ($ssh['password'] !== null && $ssh['ssh_key'] === null) {
            array_push($cmd, 'sshpass', '-p', $ssh['password']);
        }

        array_push($cmd,
            'rsync',
            '--archive',
            '--compress',
            '--mkpath',
            '-e', $sshCmd,
            $localFile,
            "{$ssh['user']}@{$ssh['host']}:{$remoteDir}",
        );

        $process = new Process($cmd, timeout: 1800);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('rsync DB upload failed: ' . trim($process->getErrorOutput()));
        }
    }

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
                    "Disk '{$diskName}' uses password auth but 'sshpass' is not installed."
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

    private function dumpAndCompress(array $db, string $outputFile): void
    {
        $process = Process::fromShellCommandline(
            'mysqldump'
            . ' --host=' . escapeshellarg($db['host'])
            . ' --port=' . (int) $db['port']
            . ' --user=' . escapeshellarg($db['user'])
            . ' --password=' . escapeshellarg($db['password'])
            . ' --single-transaction'
            . ' --quick'
            . ' --routines'
            . ' --triggers'
            . ' --events'
            . ' --set-gtid-purged=OFF'
            . ' --default-character-set=utf8mb4'
            . ' ' . escapeshellarg($db['name'])
            . ' | gzip --best > ' . escapeshellarg($outputFile),
            timeout: 1800
        );

        $process->run();

        if (!$process->isSuccessful() || !file_exists($outputFile) || filesize($outputFile) === 0) {
            throw new \RuntimeException('mysqldump failed: ' . trim($process->getErrorOutput()));
        }
    }

    private function verifyGzip(string $filePath): void
    {
        $process = new Process(['gzip', '-t', $filePath], timeout: 60);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("DB dump gzip integrity check failed: {$filePath}");
        }
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
