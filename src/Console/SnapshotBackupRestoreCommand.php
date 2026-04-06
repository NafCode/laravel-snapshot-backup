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
                            {--files-only    : Restore files only}
                            {--full          : Restore both files and database}
                            {--dump=         : Specific DB dump filename (defaults to latest for that day)}
                            {--force         : Skip all confirmation prompts (use with caution!)}
                            {--server=       : Override server ID}
                            {--app=          : Override app name}';

    // Usage examples:
    //   Full restore (latest):        snapshot-backup:restore --full
    //   Full restore (specific date): snapshot-backup:restore --full --date=2026-04-01
    //   Full restore (exact slot):    snapshot-backup:restore --full --date=2026-04-01_120000
    //   Files only:                   snapshot-backup:restore --files-only --date=2026-04-01
    //   DB only (latest dump):        snapshot-backup:restore --db-only --date=2026-04-01
    //   DB only (specific dump):      snapshot-backup:restore --db-only --date=2026-04-01 --dump=db-App-2026-04-01_060000.sql.gz
    //   Partial (sub-path):           snapshot-backup:restore --files-only --date=2026-04-01 --path=assets/uploads
    //
    // WARNING: --db-only drops and recreates the target database. Always confirm before proceeding.
    // After restore: php artisan config:clear && php artisan cache:clear

    protected $description = 'Restore files and/or database from a snapshot on backup storage';

    private array $config;

    public function handle(): int
    {
        $this->config = config('snapshot-backup');

        $serverId  = $this->option('server') ?? $this->config['server_id'];
        $appName   = $this->option('app')    ?? $this->config['app_name'];
        $firstInclude = $this->config['source']['files']['include'][0];
        $targetDir    = $this->option('target') ?? dirname($firstInclude);
        $diskName  = $this->option('disk')   ?? $this->config['disks'][0];
        $disk      = MediaService::disk($diskName);

        $snapshotsBase = "{$serverId}/{$appName}/snapshots";
        $fileSlotsBase = "{$snapshotsBase}/files";
        $dbBase        = "{$snapshotsBase}/db";

        // ── Resolve file slot and DB date ─────────────────────────────────────
        $dateOpt = $this->option('date');

        if ($dateOpt && preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $dateOpt)) {
            $fileSlot = $dateOpt;
        } else {
            $allSlots = collect($disk->directories($fileSlotsBase))
                ->map(fn ($d) => basename($d))
                ->filter(fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $d))
                ->when($dateOpt, fn ($c) => $c->filter(fn ($s) => str_starts_with($s, $dateOpt)))
                ->sort()
                ->values();

            if ($allSlots->isEmpty()) {
                $label = $dateOpt ? "for date {$dateOpt}" : 'on disk ' . $diskName;
                $this->error("No file snapshots found {$label}.");
                return self::FAILURE;
            }

            $fileSlot = $allSlots->last();
            $this->info("Using file slot: <comment>{$fileSlot}</comment>");
        }

        $dbDay = $dateOpt && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOpt)
            ? $dateOpt
            : substr($fileSlot, 0, 10);

        if (!$disk->directoryExists("{$fileSlotsBase}/{$fileSlot}")) {
            $this->error("File slot not found: {$fileSlot} on disk {$diskName}");
            return self::FAILURE;
        }

        // ── Determine what to restore ─────────────────────────────────────────
        $doFiles = !$this->option('db-only');
        $doDb    = !$this->option('files-only');

        if ($this->option('full')) {
            $doFiles = $doDb = true;
        }

        // ── File Restore (SFTP/rsync only) ────────────────────────────────────
        if ($doFiles) {
            $diskConfig = config("filesystems.disks.{$diskName}");

            if (($diskConfig['driver'] ?? '') !== 'sftp') {
                $this->error("File restore requires an SFTP disk (disk '{$diskName}' uses '{$diskConfig['driver']}').");
                $this->line('For non-SFTP disks, download the slot directory manually from your storage provider.');
                if (!$doDb) {
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
                        $this->restoreFilesViaSftp($ssh, $remoteSrc, $destPath);
                    }
                } else {
                    $includes = $this->config['source']['files']['include'];

                    $this->warn("Full file restore from slot: {$fileSlot}");
                    $this->line("Target:  <comment>{$destPath}</comment>");

                    if (!$this->option('force') && !$this->confirm('Proceed with file restore?', false)) {
                        $this->info('File restore cancelled.');
                    } else {
                        foreach ($includes as $includePath) {
                            $subDir    = basename($includePath);
                            $remoteSrc = $remoteSlot . '/' . $subDir;
                            $this->line("Restoring: <comment>{$subDir}</comment>");
                            $this->restoreFilesViaSftp($ssh, $remoteSrc, $destPath);
                        }
                    }
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
        $this->line('  <comment>chown -R www-data:www-data ' . $firstInclude . '</comment>');

        return self::SUCCESS;
    }

    // ── File restore via rsync (SFTP only) ────────────────────────────────────

    private function restoreFilesViaSftp(array $ssh, string $remoteSrc, string $localDest): void
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
        $dbPassword = $this->promptMysqlPassword($db, $local);

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

    private function promptMysqlPassword(array $db, string $localDump): ?string
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

    // ── Helpers ───────────────────────────────────────────────────────────────

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
