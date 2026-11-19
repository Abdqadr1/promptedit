<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\Exceptions\RejectedException;
use App\Services\Pixelforge\Exceptions\RetryableException;
use App\Services\Pixelforge\GenerationStateApplier;
use App\Services\Pixelforge\PixelforgeClient;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends a pending generation to Pixelforge.
 *
 * Each kind of failure gets its own retry policy:
 *  - concurrency / per-minute rate limits are expected under load: keep waiting (with growing
 *    backoff) until retryUntil(),
 *  - overloads, 5xx and network errors are retried a few times with exponential backoff, then
 *    the generation fails at no charge,
 *  - the daily limit cannot clear until the next UTC day, so it fails immediately.
 *
 * The generation's ULID is sent as the Idempotency-Key, so a retry after a lost response can
 * never create a second (billed) job.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /**
     * Consecutive overload / server / network failures tolerated before giving up.
     */
    public const int MAX_TRANSIENT_FAILURES = 5;

    public const int MAX_BACKOFF_SECONDS = 30;

    /**
     * Unexpected exceptions (bugs, bad responses) allowed before giving up.
     */
    public int $maxExceptions = 3;

    public function __construct(public Generation $generation) {}

    public function retryUntil(): DateTimeInterface
    {
        return $this->generation->created_at->addMinutes(3);
    }

    public function handle(PixelforgeClient $client, GenerationStateApplier $applier): void
    {
        $generation = $this->generation->fresh();

        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return;
        }

        try {
            $remote = $client->createGeneration($generation);
        } catch (RetryableException $exception) {
            $this->handleRetryable($generation, $exception, $applier);

            return;
        } catch (RejectedException $exception) {
            $applier->fail($generation, $exception->errorCode, $exception->getMessage());

            return;
        }

        $generation->forceFill(['submitted_at' => now()])->save();

        $applier->apply($generation, $remote);
    }

    public function failed(?Throwable $exception): void
    {
        app(GenerationStateApplier::class)->fail(
            $this->generation,
            'submission_failed',
            'Pixelforge could not accept this generation in time. You were not charged.',
        );
    }

    private function handleRetryable(Generation $generation, RetryableException $exception, GenerationStateApplier $applier): void
    {
        match ($exception->errorCode) {
            'concurrency_limit_exceeded', 'rate_limit_exceeded' => $this->release(
                $this->retryDelaySeconds($this->attempts(), $exception->retryAfterSeconds),
            ),
            'daily_limit_exceeded' => $applier->fail(
                $generation,
                'daily_limit_exceeded',
                'The daily generation limit has been reached. You were not charged — please try again tomorrow.',
            ),
            default => $this->retryTransientFailure($generation, $exception, $applier),
        };
    }

    /**
     * Overloads, server errors and network failures: back off exponentially, then give up.
     */
    private function retryTransientFailure(Generation $generation, RetryableException $exception, GenerationStateApplier $applier): void
    {
        $generation->increment('transient_failures');

        if ($generation->transient_failures >= self::MAX_TRANSIENT_FAILURES) {
            $applier->fail(
                $generation,
                $exception->errorCode ?? 'overloaded',
                'Pixelforge is overloaded right now. You were not charged — please try again.',
            );

            return;
        }

        $this->release($this->retryDelaySeconds($generation->transient_failures, $exception->retryAfterSeconds));
    }

    /**
     * Exponential backoff (2, 4, 8, … seconds, capped) that never undercuts Retry-After, plus
     * jitter so released jobs do not all retry at the same moment.
     */
    private function retryDelaySeconds(int $attempt, ?int $retryAfterSeconds): int
    {
        $exponential = min(2 ** max(1, $attempt), self::MAX_BACKOFF_SECONDS);

        return max($exponential, $retryAfterSeconds ?? 0) + random_int(0, 3);
    }
}
