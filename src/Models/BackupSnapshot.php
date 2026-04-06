<?php

namespace SnapshotBackup\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BackupSnapshot extends Model
{
    protected $fillable = [
        'server_id',
        'app_name',
        'type',
        'snapshot_date',
        'snapshot_slot',
        'status',
        'size_bytes',
        'duration_seconds',
        'error_message',
        'rsync_stats',
    ];

    protected $casts = [
        'snapshot_date'    => 'date',
        'size_bytes'       => 'integer',
        'duration_seconds' => 'integer',
    ];

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForApp(Builder $query, string $serverId, string $appName): Builder
    {
        return $query->where('server_id', $serverId)->where('app_name', $appName);
    }

    public function scopeFiles(Builder $query): Builder
    {
        return $query->where('type', 'files');
    }

    public function scopeDatabase(Builder $query): Builder
    {
        return $query->where('type', 'database');
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', 'success');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getSizeHumanAttribute(): string
    {
        if ($this->size_bytes === null) {
            return 'N/A';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = $this->size_bytes;
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function getDurationHumanAttribute(): string
    {
        if ($this->duration_seconds === null) {
            return 'N/A';
        }

        $m = intdiv($this->duration_seconds, 60);
        $s = $this->duration_seconds % 60;

        return $m > 0 ? "{$m}m {$s}s" : "{$s}s";
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }
}
