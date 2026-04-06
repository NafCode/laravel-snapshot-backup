# Laravel Snapshot Backup

rsync-based snapshot backup for Laravel applications. Backs up files and databases to remote storage (Hetzner Storage Box, or any SFTP server) with hard-link deduplication, hourly DB dumps, multi-disk mirroring, and full restore support via Artisan commands.

---

## Features

- **File snapshots** via rsync with `--link-dest` hard-linking — unchanged files are not re-transferred or re-stored; only deltas consume new space
- **Database dumps** — mysqldump piped through gzip, uploaded via rsync (SFTP disks) or Flysystem (S3, local, etc.)
- **Multi-disk mirroring** — write to multiple backup destinations on every run
- **Retention cleanup** — configurable per-type retention window (default 30 days)
- **Full restore** — files via rsync, database via `zcat | mysql`, with pre-restore failsafe dump
- **Queue isolation** — backup jobs run on a dedicated queue, never blocking app workers
- **Failure alerts** — synchronous email notifications when jobs exhaust all retries
- **Spatie Media Library** integration via `MediaService`

---

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.2+ |
| Laravel | 10+ or 11+ |
| MySQL | 5.7+ (for mysqldump) |
| rsync | 3.2.3+ (needs `--mkpath` support; both app server and remote must have it) |
| sshpass | Any (only required if using password-based SSH auth; not needed for SSH key auth) |
| Redis | Any (recommended for the dedicated backup queue) |
| spatie/laravel-medialibrary | ^10.0 or ^11.0 |

> **rsync 3.2.3+** is required for `--mkpath`. Ubuntu 22.04+ ships with rsync 3.2.7. Check with `rsync --version`.

> **sshpass** is only needed if your storage disk uses password authentication. Install with `apt install sshpass`. SSH key auth is recommended for production — no extra package, no password in process arguments.

---

## Installation

```bash
composer require nafcode/laravel-snapshot-backup
```

Publish the config file:

```bash
php artisan vendor:publish --tag=snapshot-backup-config
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag=snapshot-backup-migrations
php artisan migrate
```

---

## Configuration

All settings live in `config/snapshot-backup.php` after publishing. Most values are driven by `.env`.

### Minimal `.env` setup

```dotenv
# Enable backups on this server (default: false — must be explicitly set)
SNAPSHOT_BACKUP_ENABLED=true

# Alert recipients on permanent job failure (comma-separated)
SNAPSHOT_BACKUP_ALERT_MAIL=ops@example.com,devteam@example.com
```

The `server_id` and `app_name` are derived automatically from `APP_URL` and `APP_NAME` — no extra config needed for basic usage.

### Storage disk (filesystems.php)

Backup storage requires an **SFTP disk** for file snapshots (rsync needs SSH). Database dumps also work with S3, local, or any Flysystem driver.

Example Hetzner Storage Box disk:

```php
// config/filesystems.php
'hetzner' => [
    'driver'   => 'sftp',
    'host'     => env('HETZNER_HOST'),
    'username' => env('HETZNER_USERNAME'),
    'password' => env('HETZNER_PASSWORD'),
    'port'     => 23,
    // Hetzner sub-account SFTP root = /home. Set this so Flysystem paths
    // resolve correctly against SSH absolute paths.
    'root'     => '/home',
    'timeout'  => 36000,
],
```

Then reference it in `config/snapshot-backup.php`:

```php
'disks' => ['hetzner'],
```

#### SSH key auth (recommended)

```php
'hetzner' => [
    'driver'     => 'sftp',
    'host'       => env('HETZNER_HOST'),
    'username'   => env('HETZNER_USERNAME'),
    'privateKey' => env('HETZNER_PRIVATE_KEY_PATH'),  // path to key file on app server
    'port'       => 23,
    'root'       => '/home',
],
```

No `sshpass` required with key auth.

### Queue setup (config/queue.php)

Backup jobs run on a dedicated `backup` connection to stay isolated from the app's `default` queue:

```php
// config/queue.php
'connections' => [
    // ...
    'backup' => [
        'driver'      => 'redis',
        'connection'  => 'default',
        'queue'       => 'backup',
        'retry_after' => 7200,  // 2h — above the longest job timeout (3600s)
    ],
],
```

### Horizon setup (config/horizon.php)

Add a dedicated supervisor so backup jobs never share workers with the app:

```php
'backup-supervisor' => [
    'connection' => 'backup',
    'queue'      => ['backup'],
    'processes'  => 1,       // backups must not run concurrently
    'timeout'    => 3600,
    'nice'       => 10,      // lower CPU priority than app workers
    'tries'      => 3,
],
```

> If you don't use Horizon, add a dedicated `php artisan queue:work backup --queue=backup --timeout=3600` worker instead.

### Logging (config/logging.php)

The package logs everything to a dedicated `backup` channel. Add it or backup output will fall through to the default logger:

```php
// config/logging.php
'channels' => [
    // ...
    'backup' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/backup.log'),
        'level'  => 'debug',
        'days'   => 30,
    ],
],
```

---

## Scheduling

Add the backup commands to your `app/Console/Kernel.php`:

```php
// File snapshot — every 6 hours (adjust to taste)
$schedule->command('snapshot-backup:run --files-only')
    ->everySixHours()
    ->withoutOverlapping(120)
    ->runInBackground();

// DB dump — every hour
$schedule->command('snapshot-backup:run --db-only')
    ->hourly()
    ->withoutOverlapping(60)
    ->runInBackground();

// Retention cleanup — daily
$schedule->command('snapshot-backup:cleanup')
    ->dailyAt('03:30')
    ->withoutOverlapping(30);
```

> **`withoutOverlapping(N)`** should be ≤ the schedule interval in minutes. If a backup is still running when the next one fires, the new run is skipped rather than stacking up.

---

## Artisan Commands

```bash
# Dispatch file + DB backup jobs to the backup queue (chained: DB starts after file)
php artisan snapshot-backup:run

# Dispatch only one type
php artisan snapshot-backup:run --files-only
php artisan snapshot-backup:run --db-only

# Run synchronously — bypasses queue, useful for first-run or debugging
php artisan snapshot-backup:run --sync
php artisan snapshot-backup:run --sync --files-only
php artisan snapshot-backup:run --sync --db-only

# List all available restore points
php artisan snapshot-backup:list
php artisan snapshot-backup:list --disk=hetzner

# Restore
php artisan snapshot-backup:restore --full                          # latest everything
php artisan snapshot-backup:restore --full --date=2026-04-01       # specific day
php artisan snapshot-backup:restore --full --date=2026-04-01_120000 # exact slot
php artisan snapshot-backup:restore --files-only --date=2026-04-01
php artisan snapshot-backup:restore --db-only --date=2026-04-01
php artisan snapshot-backup:restore --db-only --date=2026-04-01 --dump=db-App-2026-04-01_060000.sql.gz
php artisan snapshot-backup:restore --files-only --date=2026-04-01 --path=assets/uploads  # partial

# Run retention cleanup manually
php artisan snapshot-backup:cleanup
php artisan snapshot-backup:cleanup --sync
```

---

## How It Works

### Remote directory structure

```
{server_id}/{app_name}/
├── latest -> snapshots/files/2026-04-06_060000   (symlink)
└── snapshots/
    ├── files/
    │   ├── 2026-04-06_060000/    ← one slot per backup run
    │   │   └── assets/           ← named after the source directory
    │   ├── 2026-04-06_000000/    ← ~95% hard-linked to previous slot
    │   └── ...  (30 days retained)
    └── db/
        ├── 2026-04-06/
        │   ├── db-App-2026-04-06_000000.sql.gz
        │   ├── db-App-2026-04-06_010000.sql.gz
        │   └── ...  (one per hour)
        └── ...  (30 days retained)
```

### File backup

1. Finds the most recent previous slot on the remote via SFTP directory listing
2. Runs rsync with `--link-dest=../PREV_SLOT/` — unchanged files are hard-linked, not re-transferred
3. Applies read-only lock (`chmod -R a-w`) after success
4. Retries up to 3× on failure (rsync exit code 24 — vanished source files — is treated as success)

### Database backup

1. `mysqldump --single-transaction | gzip --best` → local temp file
2. Verifies integrity with `gzip -t`
3. Uploads to each configured disk:
   - **SFTP disks**: rsync over SSH with `--mkpath` (reliable on first run; Flysystem SFTP `put` silently fails on fresh directories on some hosts)
   - **Other disks** (S3, local): `Storage::disk()->writeStream()`
4. Cleans up local temp file

### Job chaining

When `snapshot-backup:run` dispatches both file and DB jobs (no `--files-only`/`--db-only`), they are chained via `Bus::chain()`:

```
RunFileBackupJob → RunDatabaseBackupJob
```

This ensures the remote directory tree exists before the DB upload runs — critical on a fresh server with no previous backups.

### Restore safety

Before dropping the database during a restore, the current DB is dumped to `storage/app/snapshot-backup/pre-restore-{dbname}-{datetime}.sql.gz` as a local failsafe. If the restore import later fails, this file is available for manual recovery. It is not managed by retention — clean it up manually.

---

## SFTP Storage Compatibility

**Any SFTP server works** — the package only requires standard SSH access (for rsync) and SFTP access (for directory listing and DB uploads). There is nothing Hetzner-specific in the code.

Tested and known to work:
- Hetzner Storage Box
- Standard Linux servers (OpenSSH)
- Any SFTP-capable NAS or cloud storage gateway

Configure your SFTP disk in `config/filesystems.php` as shown in the [Configuration](#configuration) section. The only things that vary between providers are the `host`, `port`, `root`, and auth credentials.

---

## Hetzner Storage Box

Hetzner Storage Box is the primary tested target and works well. A few provider-specific behaviours to be aware of:

### Restricted shell

Hetzner Storage Box uses a heavily restricted shell. Only a small set of commands are available: `mkdir`, `mv`, `rm`, `chmod`, `ls`, `cp`, `du`, `ln`, `md5sum`.

The following are **not available** — the package avoids them:

| Not supported | How the package works around it |
|--------------|--------------------------------|
| `&&`, `\|\|`, `\|` (shell operators) | Each SSH command is a separate exec call |
| `find`, `awk`, `test`, `echo` | Directory listing done via SFTP (Flysystem), not SSH |
| `2>/dev/null` | Errors are handled in PHP, not shell-redirected |

### SFTP quirks

- **`rename` fails on non-empty directories** — the package writes directly to the final slot directory rather than an `.incomplete` → rename pattern. rsync's `--delete` corrects interrupted runs.
- **`put` silently fails on first write into a freshly created directory** — this is a phpseclib/Flysystem issue where the failure returns `null` instead of `false`, bypassing the error check. The package uses rsync over SSH for all SFTP disk uploads to bypass this entirely.
- **Recursive `rmdir` silently fails on non-empty directories** — deletion during cleanup uses Flysystem `deleteDirectory()` which handles this, or SSH `rm -rf` for individual slot cleanup.

### Sub-account root path

Hetzner sub-account SFTP CWD after login is `/home`. Set `'root' => '/home'` in your disk config so Flysystem paths align with SSH absolute paths:

```php
'hetzner' => [
    'driver'   => 'sftp',
    'host'     => env('HETZNER_HOST'),
    'username' => env('HETZNER_USERNAME'),
    'password' => env('HETZNER_PASSWORD'),
    'port'     => 23,
    'root'     => '/home',   // ← required for Hetzner sub-accounts
    'timeout'  => 36000,
],
```

### Creating a sub-account

Each server should use a **dedicated sub-account** for credential isolation — a compromised server's credentials cannot access other accounts, and decommissioning a server means deleting one sub-account.

1. Log in to [Hetzner Robot](https://robot.hetzner.com)
2. Storage Box → Sub-Accounts → **Create Sub-Account**
3. Enable **SSH**, **External reachability**, and **SFTP**
4. Set a strong password or upload an SSH public key

```dotenv
HETZNER_HOST=u123456.your-storagebox.de   # same host for all sub-accounts
HETZNER_USERNAME=u123456-sub42            # unique per server
HETZNER_PASSWORD=...
```

---

## First-Run Checklist

- [ ] `SNAPSHOT_BACKUP_ENABLED=true` in `.env`
- [ ] Storage disk configured in `config/filesystems.php`
- [ ] `disks` array in `config/snapshot-backup.php` points to that disk
- [ ] `backup` queue connection added to `config/queue.php`
- [ ] `backup-supervisor` added to `config/horizon.php` (or equivalent queue worker)
- [ ] `backup` log channel added to `config/logging.php`
- [ ] `sshpass` installed if using password auth (`apt install sshpass`)
- [ ] `rsync --version` shows 3.2.3+ on the app server
- [ ] Smoke test: `php artisan snapshot-backup:run --sync --db-only`
- [ ] Verify: `php artisan snapshot-backup:list`

---

## Data Loss Windows (Default Schedule)

| What | Max loss |
|------|---------|
| Uploaded files | ~6 hours (every 6 hours) |
| Database records | ~1 hour (every hour) |
| Data older than 30 days | Permanent — not recoverable |

---

## License

MIT
