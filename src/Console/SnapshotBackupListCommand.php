<?php

namespace SnapshotBackup\Console;

use Illuminate\Console\Command;
use SnapshotBackup\Services\RetentionService;

class SnapshotBackupListCommand extends Command
{
    protected $signature = 'snapshot-backup:list
                            {--disk=   : Disk name to query (defaults to first configured disk)}
                            {--server= : Override server ID}
                            {--app=    : Override app name}';

    protected $description = 'List available rsync snapshot restore points on backup storage';

    public function handle(RetentionService $retention): int
    {
        $serverId = $this->option('server') ?? config('snapshot-backup.server_id');
        $appName  = $this->option('app')    ?? config('snapshot-backup.app_name');
        $diskName = $this->option('disk')   ?? config('snapshot-backup.disks')[0];

        $this->info("Listing restore points for: <comment>{$serverId}/{$appName}</comment> on disk <comment>{$diskName}</comment>");
        $this->newLine();

        try {
            $snapshots = $retention->listSnapshots($serverId, $appName, $diskName);
        } catch (\Throwable $e) {
            $this->error("Could not connect to disk '{$diskName}': " . $e->getMessage());
            return self::FAILURE;
        }

        if (empty($snapshots)) {
            $this->warn('No snapshots found.');
            return self::SUCCESS;
        }

        $rows = array_map(function (array $snap, int $idx) {
            return [$idx + 1, $snap['slot'], $snap['dbDumps'], $snap['size']];
        }, $snapshots, array_keys($snapshots));

        $this->table(['#', 'File Slot (YYYY-MM-DD_HH0000)', 'DB Dumps (same day)', 'Approx Size'], $rows);

        $allDisks = config('snapshot-backup.disks');
        if (count($allDisks) > 1) {
            $this->newLine();
            $this->line('Configured disks: <comment>' . implode(', ', $allDisks) . '</comment>');
            $this->line('Use <comment>--disk=NAME</comment> to list snapshots on a specific disk.');
        }

        $this->newLine();
        $this->line('Restore commands:');
        $this->line('  <comment>php artisan snapshot-backup:restore --date=YYYY-MM-DD</comment>');
        $this->line('  <comment>php artisan snapshot-backup:restore --date=YYYY-MM-DD_HH0000</comment>');
        $this->line('  <comment>php artisan snapshot-backup:restore --date=YYYY-MM-DD --db-only</comment>');
        $this->line('  <comment>php artisan snapshot-backup:restore --date=YYYY-MM-DD_HH0000 --full</comment>');

        return self::SUCCESS;
    }
}
