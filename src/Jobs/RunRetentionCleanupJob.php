<?php

namespace SnapshotBackup\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SnapshotBackup\Services\RetentionService;

class RunRetentionCleanupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 2;
    public array $backoff = [60, 300];

    public function __construct(
        public readonly string $serverId,
        public readonly string $appName,
    ) {
        $this->onConnection(config('snapshot-backup.queue.connection'))
             ->onQueue(config('snapshot-backup.queue.name'));
    }

    public function handle(RetentionService $retention): void
    {
        Log::channel('backup')->info("Job started: RunRetentionCleanupJob [{$this->serverId}/{$this->appName}]");
        $retention->cleanup($this->serverId, $this->appName);
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('backup')->error(
            "RunRetentionCleanupJob failed [{$this->serverId}/{$this->appName}]: " . $e->getMessage()
        );
    }
}
