<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobMonitor extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'job_class',
        'job_group',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'message',
        'metadata',
        'error_message',
        'error_trace',
    ];

    protected function casts(): array
    {
        return [
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
            'metadata'    => 'array',
            'duration_ms' => 'integer',
        ];
    }

    public static function start(string $jobClass, string $group): self
    {
        return static::create([
            'job_class'  => $jobClass,
            'job_group'  => $group,
            'status'     => 'running',
            'started_at' => now(),
        ]);
    }

    public function succeed(string $message, array $metadata = []): void
    {
        $this->update([
            'status'      => 'success',
            'finished_at' => now(),
            'duration_ms' => (int) round($this->started_at->diffInMilliseconds(now())),
            'message'     => $message,
            'metadata'    => $metadata ?: null,
        ]);
    }

    public function fail(\Throwable $e): void
    {
        $this->update([
            'status'        => 'failed',
            'finished_at'   => now(),
            'duration_ms'   => (int) round($this->started_at->diffInMilliseconds(now())),
            'error_message' => mb_substr($e->getMessage(), 0, 2000),
            'error_trace'   => mb_substr($e->getTraceAsString(), 0, 4000),
        ]);
    }

    public static function purgeOlderThan(int $days = 7): int
    {
        return static::where('started_at', '<', now()->subDays($days))->delete();
    }

    public function shortName(): string
    {
        return class_basename($this->job_class);
    }

    public function durationFormatted(): string
    {
        if ($this->duration_ms === null) {
            return '—';
        }

        if ($this->duration_ms < 1000) {
            return $this->duration_ms . ' ms';
        }

        $seconds = $this->duration_ms / 1000;
        if ($seconds < 60) {
            return number_format($seconds, 1) . ' s';
        }

        $minutes = floor($seconds / 60);
        $remaining = $seconds - ($minutes * 60);
        return $minutes . 'm ' . number_format($remaining, 0) . 's';
    }
}
