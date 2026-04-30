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
    | Server Identity & Application Name
    |--------------------------------------------------------------------------
    | Used for database records, log messages, and alert emails only.
    | NOT used for remote storage paths (see remote_path below).
    */
    'server_id' => parse_url(env('APP_URL'), PHP_URL_HOST),
    'app_name'  => env('APP_NAME', 'app'),

    /*
    |--------------------------------------------------------------------------
    | Remote Backup Path
    |--------------------------------------------------------------------------
    | Fixed folder name on the SFTP sub-account root used for all backup
    | storage. Since each server has its own sub-account, a short fixed name
    | is safe and avoids spaces or special characters from APP_NAME breaking
    | SSH / borg paths.
    |
    | Override with SNAPSHOT_BACKUP_REMOTE_PATH in .env if you need to
    | match a pre-existing directory layout during migration.
    |
    | Remote layout:
    |   {remote_path}/borg-repo
    |   {remote_path}/snapshots/files/{slot}
    |   {remote_path}/snapshots/db/{date}
    |   {remote_path}/snapshots/disk-sources/{diskname}/{slot}
    */
    'remote_path' => env('SNAPSHOT_BACKUP_REMOTE_PATH', 'snapshot-backup'),

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
    |
    | keep_file_days — delete file snapshot slots (Borg archives / rsync
    |   dirs) older than N days.
    |
    | keep_db_days — delete DB date-directories older than N days.
    |   Each date-dir accumulates one dump per run (e.g. 24 dumps/day if DB
    |   runs hourly). Deleting the dir removes all dumps for that day.
    |
    | keep_disk_source_slots — keep only the N most recent disk-source
    |   backups (S3, FTP, etc.). These are full stream-copies with no
    |   deduplication, so storage grows linearly. Local file backups
    |   (Borg/rsync) are unaffected — they follow keep_file_days.
    |
    | safe_cleanup — if true (default), retention refuses to delete any
    |   backups when no successful file backup exists within the retention
    |   window. Prevents silent data loss if the source (S3 bucket, local
    |   disk) is accidentally deleted — old good backups survive until a
    |   healthy backup runs again.
    */
    'retention' => [
        'keep_file_days'         => 30,
        'keep_db_days'           => 30,
        'keep_disk_source_slots' => 2,
        'safe_cleanup'           => true,
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
