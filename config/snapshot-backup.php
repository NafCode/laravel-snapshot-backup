<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable / Disable
    |--------------------------------------------------------------------------
    | When false, none of the scheduled snapshot-backup commands will run.
    | Set SNAPSHOT_BACKUP_ENABLED=true in .env on servers where backups
    | should be active.
    */
    'enabled' => env('SNAPSHOT_BACKUP_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Server Identity
    |--------------------------------------------------------------------------
    | Derived from APP_URL hostname — automatically unique per server.
    | e.g. APP_URL=https://clinic-amsterdam.example.com
    |      → server_id = clinic-amsterdam.example.com
    */
    'server_id' => parse_url(env('APP_URL'), PHP_URL_HOST),

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    */
    'app_name' => env('APP_NAME', 'app'),

    /*
    |--------------------------------------------------------------------------
    | Backup Storage Disks
    |--------------------------------------------------------------------------
    | One or more disks defined in config/filesystems.php. Backups are written
    | to ALL listed disks on every run (mirroring). Restore defaults to the
    | first disk.
    |
    | File snapshots (rsync) only work with 'sftp' driver disks.
    | Database dumps work with any driver (sftp, s3, ftp, local).
    */
    'disks' => [
        'hetzner',
        // 's3',
    ],

    /*
    |--------------------------------------------------------------------------
    | Source Files
    |--------------------------------------------------------------------------
    | Two independent source types — both end up in the same snapshot slot.
    |
    | include  — local filesystem paths, transferred via rsync with
    |            --link-dest deduplication. Fast; requires SSH access.
    |
    | disks    — Laravel filesystem disks (e.g. 's3', 'gcs'), stream-copied
    |            via Flysystem into SLOT/{diskname}/ on each backup disk.
    |            Use when uploads live on S3 or another remote disk.
    |            No deduplication — each slot is a full copy.
    |
    | exclude  — rsync exclude patterns, applied to 'include' paths only.
    */
    'source' => [
        'files' => [
            'include' => [
                storage_path('app/public/assets'),
            ],
            'disks' => [
                // 's3',
            ],
            'exclude' => [
                '.git/',
                'node_modules/',
                '*.log',
                'cache/',
                'sessions/',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Credentials
    |--------------------------------------------------------------------------
    | Defaults to the application's DB_* env values. Override here if the
    | backup should use a dedicated read-only user.
    */
    'database' => [
        'host'     => env('DB_HOST', '127.0.0.1'),
        'port'     => env('DB_PORT', '3306'),
        'name'     => env('DB_DATABASE'),
        'user'     => env('DB_USERNAME'),
        'password' => env('DB_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | How long to keep snapshots. Files and DB are retained independently.
    | Cleanup runs automatically via the snapshot-backup:cleanup command.
    */
    'retention' => [
        'keep_file_days' => 30,
        'keep_db_days'   => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    | Backup jobs run on a dedicated, isolated queue so long-running transfers
    | never block the application's default queue workers.
    |
    | Add a 'backup' connection to config/queue.php (Redis recommended):
    |   'backup' => [
    |       'driver'      => 'redis',
    |       'connection'  => 'default',
    |       'queue'       => 'backup',
    |       'retry_after' => 7200,
    |   ]
    */
    'queue' => [
        'connection' => env('SNAPSHOT_BACKUP_QUEUE_CONNECTION', 'backup'),
        'name'       => env('SNAPSHOT_BACKUP_QUEUE_NAME', 'backup'),
        'timeout'    => 7200,
    ],

    /*
    |--------------------------------------------------------------------------
    | rsync Options
    |--------------------------------------------------------------------------
    */
    'rsync' => [
        'retry_count' => 3,
        'retry_delay' => 60,
        'ssh_timeout' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    | Email recipients for permanent backup failure alerts.
    | Sent synchronously (not via queue) so alerts fire even if Horizon is down.
    |
    | Set SNAPSHOT_BACKUP_ALERT_MAIL in .env as a comma-separated list:
    |   SNAPSHOT_BACKUP_ALERT_MAIL=ops@example.com,devteam@example.com
    | Falls back to the array below if env is not set.
    */
    'notifications' => [
        'mail' => env('SNAPSHOT_BACKUP_ALERT_MAIL')
            ? array_map('trim', explode(',', env('SNAPSHOT_BACKUP_ALERT_MAIL')))
            : [],
    ],

];
