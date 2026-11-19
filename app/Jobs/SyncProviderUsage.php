<?php

namespace App\Jobs;

use App\Services\Pixelforge\Exceptions\RetryableException;
use App\Services\Pixelforge\UsageSync;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Syncs a date range of Pixelforge usage on demand (the admin report's "Sync" button).
 */
class SyncProviderUsage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public string $startDate,
        public string $endDate,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->startDate}:{$this->endDate}";
    }

    public function handle(UsageSync $sync): void
    {
        try {
            $sync->sync(
                CarbonImmutable::parse($this->startDate, 'UTC'),
                CarbonImmutable::parse($this->endDate, 'UTC'),
            );
        } catch (RetryableException $exception) {
            $this->release(max($exception->retryAfterSeconds ?? 0, 10 * $this->attempts()));
        }
    }
}
