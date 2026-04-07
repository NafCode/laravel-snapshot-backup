<?php

namespace SnapshotBackup\Services;

use Illuminate\Support\Facades\Log;
use SnapshotBackup\Models\BackupSnapshot;
use Symfony\Component\Process\Process;

class RetentionService
{
    private array $config;

    public function __construct()
    {
        $this->config = config('snapshot-backup');
    }

    /**
     * Remove old snapshots on ALL disks.
     * Dispatches to Borg prune or SFTP slot deletion based on disk backend.
     */
    public function cleanup(?string $serverId = null, ?string $appName = null): void
    {
        $serverId ??= $this->config['server_id'];
        $appName  ??= $this->config['app_name'];

        foreach ($this->config['disks'] as $diskName) {
            $diskConfig = config("filesystems.disks.{$diskName}");
            $backend    = $diskConfig['snapshot_backend'] ?? 'rsync';

            if ($backend === 'borg') {
                $this->pruneBorgArchives($diskName, $diskConfig, $serverId, $appName);
            } else {
                $this->cleanupFileSlots($diskName, $serverId, $appName);
            }

            $this->cleanupDbDays($diskName, $serverId, $appName);
            $this->cleanupDiskSources($diskName, $serverId, $appName);
        }
    }

    /**
     * List available file snapshot slots.
     * Dispatches to Borg archive listing or SFTP directory listing based on disk backend.
     *
     * @return array<int, array{slot: string, date: string, dbDumps: int, size: string}>
     */
    public function listSnapshots(?string $serverId = null, ?string $appName = null, ?string $diskName = null): array
    {
        $serverId ??= $this->config['server_id'];
        $appName  ??= $this->config['app_name'];
        $diskName ??= $this->config['disks'][0];

        $diskConfig = config("filesystems.disks.{$diskName}");
        $backend    = $diskConfig['snapshot_backend'] ?? 'rsync';

        if ($backend === 'borg') {
            return $this->listBorgSnapshots($diskName, $diskConfig, $serverId, $appName);
        }

        $disk          = MediaService::disk($diskName);
        $fileSlotsBase = "{$serverId}/{$appName}/snapshots/files";

        $slots = collect($disk->directories($fileSlotsBase))
            ->map(fn ($d) => basename($d))
            ->filter(fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $d))
            ->sortDesc()
            ->values();

        return $slots->map(function (string $slot) use ($disk, $serverId, $appName) {
            $date    = substr($slot, 0, 10);
            $dbBase  = "{$serverId}/{$appName}/snapshots/db/{$date}";
            $dbDumps = count($disk->files($dbBase));
            $size    = $this->directorySize($disk, "{$serverId}/{$appName}/snapshots/files/{$slot}");

            return compact('slot', 'date', 'dbDumps', 'size');
        })->all();
    }

    // ── Borg retention ────────────────────────────────────────────────────────

    private function pruneBorgArchives(
        string $diskName,
        array $diskConfig,
        string $serverId,
        string $appName,
    ): void {
        $ssh      = $this->sshFromDiskConfig($diskConfig);
        $repoUrl  = $this->borgRepoUrl($ssh, $serverId, $appName);
        $borgEnv  = $this->borgEnv($ssh);
        $keepDays = $this->config['retention']['keep_file_days'];

        Log::channel('backup')->info(
            "Borg prune disk:{$diskName} {$serverId}/{$appName} (keep={$keepDays} days)"
        );

        $prune = new Process(
            ['borg', 'prune', '--keep-within', "{$keepDays}d", '--stats', $repoUrl],
            null, $borgEnv, null, 300
        );
        $prune->run();

        if (!$prune->isSuccessful()) {
            Log::channel('backup')->error(
                "borg prune failed disk:{$diskName}: " . trim($prune->getErrorOutput())
            );
        } else {
            Log::channel('backup')->info("borg prune done disk:{$diskName}\n" . $prune->getOutput());
        }

        $compact = new Process(['borg', 'compact', $repoUrl], null, $borgEnv, null, 300);
        $compact->run();

        if (!$compact->isSuccessful()) {
            Log::channel('backup')->error(
                "borg compact failed disk:{$diskName}: " . trim($compact->getErrorOutput())
            );
        } else {
            Log::channel('backup')->info("borg compact done disk:{$diskName}");
        }

        BackupSnapshot::query()
            ->forApp($serverId, $appName)
            ->where('type', 'files')
            ->where('snapshot_date', '<', now()->subDays($keepDays)->format('Y-m-d'))
            ->delete();
    }

    /**
     * @return array<int, array{slot: string, date: string, dbDumps: int, size: string}>
     */
    private function listBorgSnapshots(
        string $diskName,
        array $diskConfig,
        string $serverId,
        string $appName,
    ): array {
        $ssh     = $this->sshFromDiskConfig($diskConfig);
        $repoUrl = $this->borgRepoUrl($ssh, $serverId, $appName);
        $borgEnv = $this->borgEnv($ssh);

        $process = new Process(['borg', 'list', '--short', $repoUrl], null, $borgEnv, null, 60);
        $process->run();

        if (!$process->isSuccessful()) {
            Log::channel('backup')->error(
                "borg list failed disk:{$diskName}: " . trim($process->getErrorOutput())
            );
            return [];
        }

        $disk = MediaService::disk($diskName);

        return collect(explode("\n", trim($process->getOutput())))
            ->filter(fn ($s) => preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $s))
            ->sortDesc()
            ->values()
            ->map(function (string $slot) use ($disk, $serverId, $appName) {
                $date   = substr($slot, 0, 10);
                $dbBase = "{$serverId}/{$appName}/snapshots/db/{$date}";

                try {
                    $dbDumps = count($disk->files($dbBase));
                } catch (\Throwable) {
                    $dbDumps = 0;
                }

                return ['slot' => $slot, 'date' => $date, 'dbDumps' => $dbDumps, 'size' => '~dedup'];
            })
            ->all();
    }

    // ── rsync slot retention ──────────────────────────────────────────────────

    private function cleanupFileSlots(string $diskName, string $serverId, string $appName): void
    {
        $keepDays      = $this->config['retention']['keep_file_days'];
        $cutoff        = now()->subDays($keepDays)->format('Y-m-d_H0000');
        $disk          = MediaService::disk($diskName);
        $fileSlotsBase = "{$serverId}/{$appName}/snapshots/files";

        Log::channel('backup')->info(
            "File slot retention disk:{$diskName} {$serverId}/{$appName} (keep={$keepDays} days, cutoff={$cutoff})"
        );

        $slots = collect($disk->directories($fileSlotsBase))
            ->map(fn ($d) => basename($d))
            ->filter(fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $d))
            ->sort()
            ->values();

        $toDelete = $slots->filter(fn ($s) => $s < $cutoff);

        if ($toDelete->isEmpty()) {
            Log::channel('backup')->info("File slots: nothing to clean up.");
            return;
        }

        Log::channel('backup')->info("File slots: deleting {$toDelete->count()} slot(s).");

        foreach ($toDelete as $slot) {
            $slotPath = "{$fileSlotsBase}/{$slot}";
            try {
                $disk->deleteDirectory($slotPath);

                BackupSnapshot::query()
                    ->forApp($serverId, $appName)
                    ->where('snapshot_slot', $slot)
                    ->delete();

                Log::channel('backup')->info("Deleted file slot: {$slot}");
            } catch (\Throwable $e) {
                Log::channel('backup')->error("Failed to delete file slot {$slot}: " . $e->getMessage());
            }
        }
    }

    // ── DB retention ──────────────────────────────────────────────────────────

    private function cleanupDbDays(string $diskName, string $serverId, string $appName): void
    {
        $keepDays = $this->config['retention']['keep_db_days'];
        $cutoff   = now()->subDays($keepDays)->format('Y-m-d');
        $disk     = MediaService::disk($diskName);
        $dbBase   = "{$serverId}/{$appName}/snapshots/db";

        Log::channel('backup')->info(
            "DB day retention disk:{$diskName} {$serverId}/{$appName} (keep={$keepDays} days, cutoff={$cutoff})"
        );

        $days = collect($disk->directories($dbBase))
            ->map(fn ($d) => basename($d))
            ->filter(fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d))
            ->sort()
            ->values();

        $toDelete = $days->filter(fn ($d) => $d < $cutoff);

        if ($toDelete->isEmpty()) {
            Log::channel('backup')->info("DB days: nothing to clean up.");
            return;
        }

        Log::channel('backup')->info("DB days: deleting {$toDelete->count()} day(s).");

        foreach ($toDelete as $day) {
            $dayPath = "{$dbBase}/{$day}";
            try {
                $disk->deleteDirectory($dayPath);

                BackupSnapshot::query()
                    ->forApp($serverId, $appName)
                    ->where('type', 'database')
                    ->where('snapshot_date', $day)
                    ->delete();

                Log::channel('backup')->info("Deleted DB day: {$day}");
            } catch (\Throwable $e) {
                Log::channel('backup')->error("Failed to delete DB day {$day}: " . $e->getMessage());
            }
        }
    }

    // ── Disk source retention ─────────────────────────────────────────────────

    private function cleanupDiskSources(string $diskName, string $serverId, string $appName): void
    {
        $keepDays        = $this->config['retention']['keep_file_days'];
        $cutoff          = now()->subDays($keepDays)->format('Y-m-d_H0000');
        $disk            = MediaService::disk($diskName);
        $diskSourcesBase = "{$serverId}/{$appName}/snapshots/disk-sources";

        try {
            $sourceDirs = $disk->directories($diskSourcesBase);
        } catch (\Throwable) {
            return;
        }

        foreach ($sourceDirs as $sourceDir) {
            $sourceDirName = basename($sourceDir);
            $slotBase      = "{$diskSourcesBase}/{$sourceDirName}";

            try {
                $toDelete = collect($disk->directories($slotBase))
                    ->map(fn ($d) => basename($d))
                    ->filter(fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $d) && $d < $cutoff);

                foreach ($toDelete as $slot) {
                    try {
                        $disk->deleteDirectory("{$slotBase}/{$slot}");
                        Log::channel('backup')->info(
                            "Deleted disk-source slot: {$sourceDirName}/{$slot} on disk:{$diskName}"
                        );
                    } catch (\Throwable $e) {
                        Log::channel('backup')->error(
                            "Failed to delete disk-source slot {$sourceDirName}/{$slot}: " . $e->getMessage()
                        );
                    }
                }
            } catch (\Throwable) {
            }
        }
    }

    // ── Borg helpers ──────────────────────────────────────────────────────────

    private function borgRepoUrl(array $ssh, string $serverId, string $appName): string
    {
        return "ssh://{$ssh['user']}@{$ssh['host']}:{$ssh['port']}/./{$serverId}/{$appName}/borg-repo";
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
        ];
    }

    private function sshFromDiskConfig(array $diskConfig): array
    {
        return [
            'host'     => $diskConfig['host'],
            'port'     => (int) ($diskConfig['port'] ?? 22),
            'user'     => $diskConfig['username'],
            'ssh_key'  => $diskConfig['privateKey'] ?? null,
            'password' => $diskConfig['password'] ?? null,
            'root'     => rtrim($diskConfig['root'] ?? '/backups', '/'),
        ];
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    private function directorySize(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $path): string
    {
        try {
            $bytes = collect($disk->allFiles($path))
                ->sum(fn ($file) => $disk->size($file));

            return $this->humanBytes((int) $bytes);
        } catch (\Throwable) {
            return '?';
        }
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
