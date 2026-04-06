<?php

namespace SnapshotBackup\Services;

use Illuminate\Support\Facades\Log;
use SnapshotBackup\Models\BackupSnapshot;

class RetentionService
{
    private array $config;

    public function __construct()
    {
        $this->config = config('snapshot-backup');
    }

    /**
     * Remove old snapshots on ALL disks.
     * File slots and DB date-dirs are retained independently.
     */
    public function cleanup(?string $serverId = null, ?string $appName = null): void
    {
        $serverId ??= $this->config['server_id'];
        $appName  ??= $this->config['app_name'];

        foreach ($this->config['disks'] as $diskName) {
            $this->cleanupFileSlots($diskName, $serverId, $appName);
            $this->cleanupDbDays($diskName, $serverId, $appName);
        }
    }

    /**
     * Delete file snapshot slots older than keep_file_days.
     */
    private function cleanupFileSlots(string $diskName, string $serverId, string $appName): void
    {
        $keepDays      = $this->config['retention']['keep_file_days'];
        $cutoff        = now()->subDays($keepDays)->format('Y-m-d_H0000');
        $disk          = MediaService::disk($diskName);
        $fileSlotsBase = "{$serverId}/{$appName}/snapshots/files";

        Log::channel('backup')->info("File slot retention disk:{$diskName} {$serverId}/{$appName} (keep={$keepDays} days, cutoff={$cutoff})");

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

    /**
     * Delete DB date-directories older than keep_db_days.
     */
    private function cleanupDbDays(string $diskName, string $serverId, string $appName): void
    {
        $keepDays = $this->config['retention']['keep_db_days'];
        $cutoff   = now()->subDays($keepDays)->format('Y-m-d');
        $disk     = MediaService::disk($diskName);
        $dbBase   = "{$serverId}/{$appName}/snapshots/db";

        Log::channel('backup')->info("DB day retention disk:{$diskName} {$serverId}/{$appName} (keep={$keepDays} days, cutoff={$cutoff})");

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

    /**
     * List available file snapshot slots.
     *
     * @return array<int, array{slot: string, date: string, dbDumps: int, size: string}>
     */
    public function listSnapshots(?string $serverId = null, ?string $appName = null, ?string $diskName = null): array
    {
        $serverId ??= $this->config['server_id'];
        $appName  ??= $this->config['app_name'];
        $diskName ??= $this->config['disks'][0];

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

    // ── Internal Helpers ──────────────────────────────────────────────────────

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
