<?php

namespace SnapshotBackup\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use SnapshotBackup\Notifications\SnapshotBackupFailed;
use SnapshotBackup\Services\SnapshotService;

class RunFileBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 3;
    public array $backoff = [60, 120, 300];

    public function __construct(
        public readonly string $serverId,
        public readonly string $appName,
    ) {
        $this->onConnection(config('snapshot-backup.queue.connection'))
             ->onQueue(config('snapshot-backup.queue.name'));
    }

    public function handle(SnapshotService $snapshots): void
    {
        Log::channel('backup')->info("Job started: RunFileBackupJob [{$this->serverId}/{$this->appName}]");
        $snapshots->runFileBackup($this->serverId, $this->appName);
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('backup')->error(
            "RunFileBackupJob permanently failed [{$this->serverId}/{$this->appName}]: " . $e->getMessage()
        );

        $emails = array_filter((array) config('snapshot-backup.notifications.mail'));

        if (!empty($emails)) {
            Notification::route('mail', $emails)
                ->notifyNow(new SnapshotBackupFailed($this->serverId, $this->appName, 'files', $e));
        }
    }
}
