<?php

namespace SnapshotBackup\Console;

use Illuminate\Console\Command;
use SnapshotBackup\Services\MediaService;
use Symfony\Component\Process\Process;

class SnapshotBackupRestoreCommand extends Command
{
    protected $signature = 'snapshot-backup:restore
                            {--date=         : Snapshot date to restore from (YYYY-MM-DD). Defaults to latest.}
                            {--disk=         : Disk to restore from (defaults to first configured disk)}
                            {--target=       : Local directory to restore files into. Defaults to app_dir in config.}
                            {--path=         : Partial restore: restore only this sub-path (e.g. storage/app/uploads)}
                            {--db-only       : Restore database only}
                            {--files-only    : Restore local file paths only (rsync / borg extract)}
                            {--disks-only    : Restore disk sources only (S3, etc. — stream copy back to origin disk)}
                            {--full          : Restore local files, disk sources, and database}
                            {--dump=         : Specific DB dump filename (defaults to latest for that day)}
                            {--force         : Skip all confirmation prompts (use with caution!)}
                            {--server=       : Override server ID}
                            {--app=          : Override app name}';

    // Usage examples:
    //   Full restore (latest):        snapshot-backup:restore --full
    //   Full restore (specific date): snapshot-backup:restore --full --date=2026-04-01
    //   Full restore (exact slot):    snapshot-backup:restore --full --date=2026-04-01_120000
    //   Local files only:             snapshot-backup:restore --files-only --date=2026-04-01
    //   Disk sources (S3) only:       snapshot-backup:restore --disks-only --date=2026-04-01
    //   DB only (latest dump):        snapshot-backup:restore --db-only
    //   DB only (specific date):      snapshot-backup:restore --db-only --date=2026-04-01
    //   DB only (specific dump):      snapshot-backup:restore --db-only --date=2026-04-01 --dump=db-App-2026-04-01_060000.sql.gz
    //   Partial restore (rsync disk): snapshot-backup:restore --files-only --date=2026-04-01 --path=assets/uploads
    //   Partial restore (Borg disk):  snapshot-backup:restore --files-only --date=2026-04-01 --path=var/www/myapp/storage/app/public/assets/uploads
    //
    // --path on Borg disks:
    //   Borg stores files at their absolute path without a leading slash, e.g.:
    //     var/www/myapp/storage/app/public/assets/patient-docs/
    //   The --path value must match that stored path prefix — not just the directory name.
    //   Run `borg list REPO::ARCHIVE | head` to inspect stored paths.
    //   Without --path, the full archive is restored (no path knowledge needed).
    //
    // --db-only: does not query file archives — safe even if no file backups have run yet.
    // WARNING: --db-only drops and recreates the target database. Always confirm before proceeding.
    // After restore: php artisan config:clear && php artisan cache:clear

    protected $description = 'Restore files and/or database from a snapshot on backup storage';

    private array $config;

    public function handle(): int
    {
        $this->config = config('snapshot-backup');

        $serverId     = $this->option('server') ?? $this->config['server_id'];
        $appName      = $this->option('app')    ?? $this->config['app_name'];
        $includes     = $this->config['source']['files']['include'];
        $firstInclude = $includes[0] ?? null;
        $targetDir    = $this->option('target') ?? ($firstInclude ? dirname($firstInclude) : storage_path('app'));
        $diskName     = $this->option('disk')   ?? $this->config['disks'][0];
        $disk         = MediaService::disk($diskName);

        $diskConfig = config("filesystems.disks.{$diskName}");
        $backend    = $diskConfig['snapshot_backend'] ?? 'rsync';

        $snapshotsBase = "{$serverId}/{$appName}/snapshots";
        $fileSlotsBase = "{$snapshotsBase}/files";
        $dbBase        = "{$snapshotsBase}/db";

        // ── Resolve file slot and DB day ──────────────────────────────────────
        $dateOpt  = $this->option('date');
        $fileSlot = null;
        $dbDay    = null;
        $ssh      = null;
        $repoUrl  = null;
        $borgEnv  = [];

        $dbOnly = $this->option('db-only') && !$this->option('full');

        if ($dbOnly) {
            // Derive DB day from --date directly, or default to today.
            if ($dateOpt && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOpt)) {
                $dbDay = $dateOpt;
            } elseif ($dateOpt && preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $dateOpt)) {
                $dbDay = substr($dateOpt, 0, 10);
            } else {
                $dbDay = now()->format('Y-m-d');
            }
        } else {
            if ($backend === 'borg') {
                $ssh     = $this->sshFromDisk($diskConfig);
                $repoUrl = $this->borgRepoUrl($ssh, $serverId, $appName);
                $borgEnv = $this->borgEnv($ssh);

                $allSlots = $this->listBorgArchives($repoUrl, $borgEnv);

                if ($allSlots->isEmpty()) {
                    $this->error("No Borg archives found in repo for {$serverId}/{$appName}.");
                    return self::FAILURE;
                }
            } else {
                $allSlots = collect($disk->directories($fileSlotsBase))
                    ->map(fn ($d) => basename($d))
                    ->filter(fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $d));
            }

            if ($dateOpt && preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $dateOpt)) {
                $fileSlot = $allSlots->contains($dateOpt) ? $dateOpt : null;
                if (!$fileSlot) {
                    $this->error("Snapshot slot '{$dateOpt}' not found on disk {$diskName}.");
                    return self::FAILURE;
                }
            } else {
                $filtered = $allSlots
                    ->when($dateOpt, fn ($c) => $c->filter(fn ($s) => str_starts_with($s, $dateOpt)))
                    ->sort()
                    ->values();

                if ($filtered->isEmpty()) {
                    $label = $dateOpt ? "for date {$dateOpt}" : 'on disk ' . $diskName;
                    $this->error("No file snapshots found {$label}.");
                    return self::FAILURE;
                }

                $fileSlot = $filtered->last();
                $this->info("Using snapshot slot: <comment>{$fileSlot}</comment>");
            }

            $dbDay = $dateOpt && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOpt)
                ? $dateOpt
                : substr($fileSlot, 0, 10);

            if ($backend !== 'borg' && !$disk->directoryExists("{$fileSlotsBase}/{$fileSlot}")) {
                $this->error("File slot not found: {$fileSlot} on disk {$diskName}");
                return self::FAILURE;
            }
        }

        // ── Determine what to restore ─────────────────────────────────────────
        $doFiles = !$this->option('db-only') && !$this->option('disks-only');
        $doDisks = !$this->option('db-only') && !$this->option('files-only');
        $doDb    = !$this->option('files-only') && !$this->option('disks-only');

        if ($this->option('full')) {
            $doFiles = $doDisks = $doDb = true;
        }
        if ($this->option('disks-only')) {
            $doFiles = $doDb = false;
            $doDisks = true;
        }

        // ── File Restore ──────────────────────────────────────────────────────
        if ($doFiles) {
            if ($backend === 'borg') {
                $this->warn("File restore from Borg archive: {$fileSlot}");

                if (!$this->option('force') && !$this->confirm('Proceed with file restore?', false)) {
                    $this->info('File restore cancelled.');
                } else {
                    $this->restoreFilesViaBorg($repoUrl, $borgEnv, $fileSlot, $this->option('path'));
                }
            } else {
                if (($diskConfig['driver'] ?? '') !== 'sftp') {
                    $this->error("File restore requires an SFTP disk (disk '{$diskName}' uses '{$diskConfig['driver']}').");
                    $this->line('For non-SFTP disks, download the slot directory manually from your storage provider.');
                    if (!$doDb && !$doDisks) {
                        return self::FAILURE;
                    }
                } else {
                    $ssh        = $this->sshFromDisk($diskConfig);
                    $destPath   = rtrim($targetDir, '/') . '/';
                    $remoteSlot = $ssh['root'] . "/{$fileSlotsBase}/{$fileSlot}";

                    if ($this->option('path')) {
                        $subPath   = ltrim($this->option('path'), '/');
                        $remoteSrc = $remoteSlot . '/' . $subPath . '/';
                        $destPath  = $destPath . $subPath . '/';
                        @mkdir($destPath, 0755, true);

                        $this->warn("Partial restore: {$subPath}");
                        $this->line("Source:  <comment>{$remoteSrc}</comment>");
                        $this->line("Target:  <comment>{$destPath}</comment>");

                        if (!$this->option('force') && !$this->confirm('Proceed with file restore?', false)) {
                            $this->info('File restore cancelled.');
                        } else {
                            $this->restoreFilesViaRsync($ssh, $remoteSrc, $destPath);
                        }
                    } else {
                        $this->warn("Full file restore from slot: {$fileSlot}");
                        $this->line("Target:  <comment>{$destPath}</comment>");

                        if (!$this->option('force') && !$this->confirm('Proceed with file restore?', false)) {
                            $this->info('File restore cancelled.');
                        } else {
                            foreach ($includes as $includePath) {
                                $subDir    = basename($includePath);
                                $remoteSrc = $remoteSlot . '/' . $subDir;
                                $this->line("Restoring: <comment>{$subDir}</comment>");
                                $this->restoreFilesViaRsync($ssh, $remoteSrc, $destPath);
                            }
                        }
                    }
                }
            }
        }

        // ── Disk Source Restore (S3, etc. — Flysystem stream copy) ───────────
        if ($doDisks) {
            $sourceDiskNames = $this->config['source']['files']['disks'] ?? [];

            if (empty($sourceDiskNames)) {
                $this->line('No source disks configured — skipping disk restore.');
            } else {
                foreach ($sourceDiskNames as $sourceDiskName) {
                    $sftpSlotDir = "{$serverId}/{$appName}/snapshots/disk-sources/{$sourceDiskName}/{$fileSlot}";

                    if (!$disk->directoryExists($sftpSlotDir)) {
                        $this->warn("No backup found for disk '{$sourceDiskName}' in slot {$fileSlot} — skipping.");
                        continue;
                    }

                    $this->warn("Disk restore: {$sourceDiskName}");
                    $this->line("Source slot: <comment>{$fileSlot}/{$sourceDiskName}</comment>");
                    $this->line("Target disk: <comment>{$sourceDiskName}</comment>");

                    if (!$this->option('force') && !$this->confirm(
                        "Restore all files from backup into disk '{$sourceDiskName}'? Existing files will be overwritten.",
                        false
                    )) {
                        $this->info("Disk restore for '{$sourceDiskName}' cancelled.");
                        continue;
                    }

                    $this->restoreDiskSource($disk, $sftpSlotDir, $sourceDiskName);
                }
            }
        }

        // ── Database Restore (any disk via Storage facade) ────────────────────
        if ($doDb) {
            $dbDayDir = "{$dbBase}/{$dbDay}";
            $dumpFile = $this->option('dump');

            if (!$dumpFile) {
                $dumps = collect($disk->files($dbDayDir))
                    ->map(fn ($f) => basename($f))
                    ->filter(fn ($f) => str_ends_with($f, '.sql.gz'))
                    ->sort()
                    ->values();

                if ($dumps->isEmpty()) {
                    $this->error("No DB dump found for day {$dbDay}.");
                    return self::FAILURE;
                }

                $dumpFile = $dumps->last();
                $this->info("Using latest dump: <comment>{$dumpFile}</comment>");
            }

            $this->warn("Database restore: {$dumpFile}");
            $this->line("Target DB: <comment>" . $this->config['database']['name'] . "</comment>");

            if (!$this->option('force') && !$this->confirm(
                "DANGER: This will DROP and recreate database '" . $this->config['database']['name'] . "'. Proceed?",
                false
            )) {
                $this->info('Database restore cancelled.');
                return self::SUCCESS;
            }

            $this->restoreDatabaseFromDisk($disk, "{$dbDayDir}/{$dumpFile}");
        }

        $this->newLine();
        $this->info('Restore complete.');
        $this->line('Post-restore checklist:');
        $this->line('  <comment>php artisan config:clear && php artisan cache:clear</comment>');
        $this->line('  <comment>php artisan migrate --force   (if needed)</comment>');
        if ($firstInclude) {
            $this->line('  <comment>chown -R www-data:www-data ' . $firstInclude . '</comment>');
        }

        return self::SUCCESS;
    }

    // ── File restore via Borg extract ─────────────────────────────────────────

    private function restoreFilesViaBorg(
        string $repoUrl,
        array $borgEnv,
        string $archiveName,
        ?string $subPath,
    ): void {
        $cmd = ['borg', 'extract', '--progress', "{$repoUrl}::{$archiveName}"];

        if ($subPath) {
            $cmd[] = ltrim($subPath, '/');
        }

        $this->info('Starting borg extract (restores to original absolute paths)...');

        $process = new Process($cmd, '/', $borgEnv, null, 3600);
        $process->run(function ($_type, $buffer) {
            $this->output->write($buffer);
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('borg extract failed: ' . $process->getErrorOutput());
        }

        $this->info('Borg file restore complete.');
    }

    // ── File restore via rsync (SFTP only) ────────────────────────────────────

    private function restoreFilesViaRsync(array $ssh, string $remoteSrc, string $localDest): void
    {
        $sshCmd = $this->buildSshCmd($ssh);

        $this->info('Starting rsync restore...');

        $process = new Process([
            'rsync',
            '--archive', '--compress', '--human-readable',
            '--delete', '--stats', '--progress', '--chmod=ugo+rw',
            '-e', $sshCmd,
            "{$ssh['user']}@{$ssh['host']}:{$remoteSrc}",
            $localDest,
        ], timeout: 3600);

        $process->run(function ($_type, $buffer) {
            $this->output->write($buffer);
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('rsync restore failed: ' . $process->getErrorOutput());
        }
    }

    // ── Disk source restore (S3, etc.) ────────────────────────────────────────

    private function restoreDiskSource(
        \Illuminate\Contracts\Filesystem\Filesystem $backupDisk,
        string $sftpSlotDir,
        string $targetDiskName,
    ): void {
        $targetDisk = MediaService::disk($targetDiskName);
        $files      = $backupDisk->allFiles($sftpSlotDir);
        $prefixLen  = strlen(rtrim($sftpSlotDir, '/')) + 1;
        $copied     = 0;
        $errors     = 0;

        $this->info('Restoring ' . count($files) . " file(s) to disk '{$targetDiskName}'...");

        foreach ($files as $backupPath) {
            $relativePath = substr($backupPath, $prefixLen);
            try {
                $stream = $backupDisk->readStream($backupPath);
                if (!is_resource($stream)) {
                    $errors++;
                    continue;
                }
                $targetDisk->writeStream($relativePath, $stream);
                fclose($stream);
                $copied++;
            } catch (\Throwable $e) {
                $errors++;
                $this->warn("  Failed: {$relativePath} — " . $e->getMessage());
            }
        }

        $this->info("Disk '{$targetDiskName}' restored: {$copied} copied, {$errors} errors.");

        if ($errors > 0 && $copied === 0) {
            throw new \RuntimeException("Disk restore failed: no files copied to '{$targetDiskName}'.");
        }
    }

    // ── Database restore via Storage facade (any disk) ────────────────────────

    private function restoreDatabaseFromDisk(
        \Illuminate\Contracts\Filesystem\Filesystem $disk,
        string $remotePath
    ): void {
        $local = sys_get_temp_dir() . '/restore-' . basename($remotePath);

        $this->info('Downloading DB dump...');

        $stream = $disk->readStream($remotePath);
        if (!$stream) {
            throw new \RuntimeException("Could not read dump from disk: {$remotePath}");
        }

        $written = file_put_contents($local, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        if ($written === false || $written === 0) {
            @unlink($local);
            throw new \RuntimeException("Failed to download dump from disk: {$remotePath}");
        }

        $gz = new Process(['gzip', '-t', $local], timeout: 60);
        $gz->run();
        if (!$gz->isSuccessful()) {
            @unlink($local);
            throw new \RuntimeException('DB dump integrity check failed.');
        }

        $db         = $this->config['database'];
        $dbPassword = $this->promptMysqlPassword($db);

        if ($dbPassword === null) {
            @unlink($local);
            throw new \RuntimeException('Aborting: could not authenticate to MySQL after 3 attempts.');
        }

        $this->info('Creating pre-restore backup of current database...');
        $this->dumpCurrentDatabase($db, $dbPassword);

        $this->info('Dropping and recreating database...');
        $drop = new Process([
            'mysql',
            '-h', $db['host'], '-P', $db['port'],
            '-u', $db['user'], '-p' . $dbPassword,
            '-e', "DROP DATABASE IF EXISTS `{$db['name']}`; CREATE DATABASE `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
        ], timeout: 60);
        $drop->run();

        if (!$drop->isSuccessful()) {
            @unlink($local);
            throw new \RuntimeException('Failed to recreate database: ' . $drop->getErrorOutput());
        }

        $this->info('Importing dump...');
        $import = Process::fromShellCommandline(
            'zcat ' . escapeshellarg($local)
            . ' | mysql -h ' . escapeshellarg($db['host'])
            . ' -P ' . (int) $db['port']
            . ' -u ' . escapeshellarg($db['user'])
            . ' -p' . escapeshellarg($dbPassword)
            . ' ' . escapeshellarg($db['name']),
            timeout: 3600
        );
        $import->run();

        @unlink($local);

        if (!$import->isSuccessful()) {
            throw new \RuntimeException('Database import failed: ' . $import->getErrorOutput());
        }

        $this->info('Database restored successfully.');
    }

    // ── Pre-restore failsafe dump ─────────────────────────────────────────────

    private function dumpCurrentDatabase(array $db, string $dbPassword): void
    {
        $dir = storage_path('app/snapshot-backup');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = 'pre-restore-' . $db['name'] . '-' . now()->format('Y-m-d_His') . '.sql.gz';
        $path     = $dir . '/' . $filename;

        $process = Process::fromShellCommandline(
            'mysqldump --single-transaction --quick --routines --triggers --events'
            . ' -h ' . escapeshellarg($db['host'])
            . ' -P ' . (int) $db['port']
            . ' -u ' . escapeshellarg($db['user'])
            . ' -p' . escapeshellarg($dbPassword)
            . ' ' . escapeshellarg($db['name'])
            . ' | gzip --best > ' . escapeshellarg($path),
            timeout: 1800
        );
        $process->run();

        if (!$process->isSuccessful()) {
            $this->warn('Pre-restore backup failed — proceeding anyway.');
            $this->warn(trim($process->getErrorOutput()));
            return;
        }

        $this->info("Pre-restore backup saved: {$path}");
    }

    // ── MySQL password prompt (up to 3 attempts) ─────────────────────────────

    private function promptMysqlPassword(array $db): ?string
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $password = (string) $this->secret(
                "Enter MySQL password for user \"{$db['user']}\""
                . ($attempt > 1 ? " (attempt {$attempt}/3)" : '')
            );

            $check = new Process([
                'mysql',
                '-h', $db['host'], '-P', $db['port'],
                '-u', $db['user'], '-p' . $password,
                '-e', 'SELECT 1',
            ], timeout: 10);
            $check->run();

            if ($check->isSuccessful()) {
                return $password;
            }

            $this->error('Access denied — wrong password.');
        }

        return null;
    }

    // ── Borg helpers ──────────────────────────────────────────────────────────

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function listBorgArchives(string $repoUrl, array $borgEnv): \Illuminate\Support\Collection
    {
        $process = new Process(['borg', 'list', '--short', $repoUrl], null, $borgEnv, null, 60);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->warn('borg list failed: ' . trim($process->getErrorOutput()));
            return collect();
        }

        return collect(explode("\n", trim($process->getOutput())))
            ->filter(fn ($s) => preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $s))
            ->values();
    }

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
            'BORG_BASE_DIR'   => storage_path('app/snapshot-backup/borg'),
        ];
    }

    // ── SSH / rsync helpers ───────────────────────────────────────────────────

    private function sshFromDisk(array $diskConfig): array
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

    private function buildSshCmd(array $ssh): string
    {
        $useKey = !empty($ssh['ssh_key']);

        $parts = $useKey
            ? ['ssh', '-i', $ssh['ssh_key'], '-o', 'BatchMode=yes']
            : ['sshpass', '-p', $ssh['password'], 'ssh', '-o', 'BatchMode=no'];

        return implode(' ', array_merge($parts, [
            '-p', $ssh['port'],
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'ConnectTimeout=' . $this->config['rsync']['ssh_timeout'],
        ]));
    }
}
