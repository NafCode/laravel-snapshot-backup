<?php

namespace SnapshotBackup\Console;

use Illuminate\Console\Command;
use SnapshotBackup\Jobs\RunRetentionCleanupJob;
use SnapshotBackup\Services\RetentionService;

class SnapshotBackupCleanupCommand extends Command
{
    protected $signature = 'snapshot-backup:cleanup
                            {--sync    : Run synchronously instead of dispatching to queue}
                            {--server= : Override server ID}
                            {--app=    : Override app name}';

    protected $description = 'Delete rsync snapshots beyond the configured retention window';

    public function handle(RetentionService $retention): int
    {
        if (!config('snapshot-backup.enabled')) {
            $this->line('Snapshot backup is disabled (SNAPSHOT_BACKUP_ENABLED is not true). Skipping.');
            return self::SUCCESS;
        }

        $serverId = $this->option('server') ?? config('snapshot-backup.server_id');
        $appName  = $this->option('app')    ?? config('snapshot-backup.app_name');

        if ($this->option('sync')) {
            $this->info('Running retention cleanup synchronously...');
            $retention->cleanup($serverId, $appName);
            $this->info('Cleanup complete.');
        } else {
            RunRetentionCleanupJob::dispatch($serverId, $appName);
            $this->info('Retention cleanup job dispatched → queue: ' . config('snapshot-backup.queue.name'));
        }

        return self::SUCCESS;
    }
}
