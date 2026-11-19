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
 * Rate limits, the 3-job concurrency limit and overloads are expected; the job releases itself
 * honoring Retry-After until it gets through. The generation's ULID is sent as the
 * Idempotency-Key, so a retry after a lost response can never create a second (billed) job.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /**
     * Unexpected exceptions (bugs, bad responses) allowed before giving up.
     */
    public int $maxExceptions = 3;

    public function __construct(public Generation $generation) {}

    public function retryUntil(): DateTimeInterface
    {
        return $this->generation->created_at->addMinutes(30);
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
            $this->release($this->retryDelay($exception));

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

    private function retryDelay(RetryableException $exception): int
    {
        $backoff = $exception->retryAfterSeconds ?? min(2 ** $this->attempts(), 60);

        return max(1, $backoff) + random_int(0, 3);
    }
}
