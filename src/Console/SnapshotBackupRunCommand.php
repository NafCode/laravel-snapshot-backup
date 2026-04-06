<?php

namespace SnapshotBackup\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use SnapshotBackup\Jobs\RunDatabaseBackupJob;
use SnapshotBackup\Jobs\RunFileBackupJob;
use SnapshotBackup\Services\DatabaseDumpService;
use SnapshotBackup\Services\SnapshotService;

class SnapshotBackupRunCommand extends Command
{
    protected $signature = 'snapshot-backup:run
                            {--files-only  : Run file backup only}
                            {--db-only     : Run database backup only}
                            {--sync        : Run synchronously (no queue dispatch)}';

    protected $description = 'Dispatch rsync snapshot backup jobs to the backup queue';

    public function handle(): int
    {
        if (!config('snapshot-backup.enabled')) {
            $this->line('Snapshot backup is disabled (SNAPSHOT_BACKUP_ENABLED is not true). Skipping.');
            return self::SUCCESS;
        }

        $serverId = config('snapshot-backup.server_id');
        $appName  = config('snapshot-backup.app_name');

        $doFiles = !$this->option('db-only');
        $doDb    = !$this->option('files-only');
        $sync    = $this->option('sync');

        if ($sync) {
            if ($doFiles) {
                $this->info('Running file backup synchronously...');
                app(SnapshotService::class)->runFileBackup($serverId, $appName);
                $this->info('File backup complete.');
            }
            if ($doDb) {
                $this->info('Running database backup synchronously...');
                app(DatabaseDumpService::class)->run($serverId, $appName);
                $this->info('Database backup complete.');
            }
        } elseif ($doFiles && $doDb) {
            // Chain: DB job runs only after file job succeeds — ensures
            // snapshots/ directory exists on the remote before DB upload.
            Bus::chain([
                new RunFileBackupJob($serverId, $appName),
                new RunDatabaseBackupJob($serverId, $appName),
            ])->onConnection(config('snapshot-backup.queue.connection'))
              ->onQueue(config('snapshot-backup.queue.name'))
              ->dispatch();
            $this->info('File + database backup jobs chained → queue: ' . config('snapshot-backup.queue.name'));
        } else {
            if ($doFiles) {
                RunFileBackupJob::dispatch($serverId, $appName);
                $this->info('File backup job dispatched → queue: ' . config('snapshot-backup.queue.name'));
            }
            if ($doDb) {
                RunDatabaseBackupJob::dispatch($serverId, $appName);
                $this->info('Database backup job dispatched → queue: ' . config('snapshot-backup.queue.name'));
            }
        }

        return self::SUCCESS;
    }
}
