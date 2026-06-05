<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\JobMonitor;

trait TracksExecution
{
    protected ?JobMonitor $monitor = null;

    protected function trackStart(): void
    {
        $this->monitor = JobMonitor::start(static::class, $this->monitorGroup());
    }

    protected function trackSuccess(string $message, array $metadata = []): void
    {
        $this->monitor?->succeed($message, $metadata);
    }

    protected function trackFailure(\Throwable $e): void
    {
        $this->monitor?->fail($e);
    }

    abstract protected function monitorGroup(): string;
}
