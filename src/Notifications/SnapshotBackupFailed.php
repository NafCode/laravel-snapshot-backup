<?php

namespace SnapshotBackup\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SnapshotBackupFailed extends Notification
{
    public function __construct(
        private readonly string $serverId,
        private readonly string $appName,
        private readonly string $type,        // 'files' or 'database'
        private readonly \Throwable $exception,
    ) {}

    public function via(mixed $_notifiable): array
    {
        if (!empty(array_filter((array) config('snapshot-backup.notifications.mail')))) {
            return ['mail'];
        }

        return [];
    }

    public function toMail(mixed $_notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject("[Backup Failed] {$this->serverId}/{$this->appName} — " . ucfirst($this->type))
            ->greeting('Backup job failed!')
            ->line("**Server:** {$this->serverId}")
            ->line("**Application:** {$this->appName}")
            ->line("**Type:** " . ucfirst($this->type) . ' backup')
            ->line("**Error:** " . $this->exception->getMessage())
            ->line('Please investigate and restore from the last successful snapshot if needed.')
            ->salutation('Laravel Snapshot Backup');
    }
}
