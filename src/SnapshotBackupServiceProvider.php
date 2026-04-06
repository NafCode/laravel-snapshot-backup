<?php

namespace SnapshotBackup;

use Illuminate\Support\ServiceProvider;
use SnapshotBackup\Console\SnapshotBackupRunCommand;
use SnapshotBackup\Console\SnapshotBackupListCommand;
use SnapshotBackup\Console\SnapshotBackupCleanupCommand;
use SnapshotBackup\Console\SnapshotBackupRestoreCommand;

class SnapshotBackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/snapshot-backup.php',
            'snapshot-backup'
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Publish config
            $this->publishes([
                __DIR__ . '/../config/snapshot-backup.php' => config_path('snapshot-backup.php'),
            ], 'snapshot-backup-config');

            // Publish migrations
            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'snapshot-backup-migrations');

            $this->commands([
                SnapshotBackupRunCommand::class,
                SnapshotBackupListCommand::class,
                SnapshotBackupCleanupCommand::class,
                SnapshotBackupRestoreCommand::class,
            ]);
        }
    }
}
