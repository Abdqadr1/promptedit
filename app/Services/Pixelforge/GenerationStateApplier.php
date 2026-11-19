<?php

namespace App\Services\Pixelforge;

use App\Enums\GenerationStatus;
use App\Jobs\StoreGeneratedImage;
use App\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The single place a generation's state changes. Webhooks, the submit job and the reconciler all
 * go through here, so ordering and duplicate rules are enforced once:
 *  - states only move forward (pending → queued → processing → succeeded|failed),
 *  - succeeded and failed are final, so the charge is written exactly once,
 *  - failed generations always cost zero.
 */
class GenerationStateApplier
{
    public function __construct(private readonly Pricing $pricing) {}

    /**
     * Applies a Pixelforge generation object (from a webhook, create response or poll).
     *
     * @param  array<string, mixed>  $remote
     */
    public function apply(Generation $generation, array $remote): Generation
    {
        $succeeded = false;

        $generation = DB::transaction(function () use ($generation, $remote, &$succeeded): Generation {
            $locked = Generation::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($locked->provider_id === null && is_string($remote['id'] ?? null)) {
                $locked->provider_id = $remote['id'];
            }

            if ($locked->rates === null && is_array($remote['rates'] ?? null)) {
                $locked->rates = $remote['rates'];
            }

            $locked->last_synced_at = now();

            $next = GenerationStatus::tryFrom((string) ($remote['status'] ?? ''));

            if ($next !== null && $locked->status->canTransitionTo($next)) {
                $locked->status = $next;

                match ($next) {
                    GenerationStatus::Succeeded => $this->fillSuccess($locked, $remote),
                    GenerationStatus::Failed => $this->fillFailure(
                        $locked,
                        (string) ($remote['error']['code'] ?? 'generation_failed'),
                        (string) ($remote['error']['message'] ?? 'The generation failed.'),
                        $this->parseTime($remote['completed_at'] ?? null),
                    ),
                    default => null,
                };

                $succeeded = $next === GenerationStatus::Succeeded;
            }

            $locked->save();

            return $locked;
        });

        if ($succeeded) {
            StoreGeneratedImage::dispatch($generation);
        }

        return $generation;
    }

    /**
     * Marks a generation failed locally (e.g. Pixelforge rejected it or it could never be submitted).
     */
    public function fail(Generation $generation, string $errorCode, string $message): Generation
    {
        return DB::transaction(function () use ($generation, $errorCode, $message): Generation {
            $locked = Generation::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->canTransitionTo(GenerationStatus::Failed)) {
                $locked->status = GenerationStatus::Failed;
                $this->fillFailure($locked, $errorCode, $message, null);
                $locked->save();
            }

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function fillSuccess(Generation $generation, array $remote): void
    {
        /** @var array<string, int> $usage */
        $usage = (array) ($remote['usage'] ?? []);
        /** @var array<string, string> $rates */
        $rates = $generation->rates ?? (array) ($remote['rates'] ?? []);

        $providerCost = Pricing::providerCostNanos($usage, $rates);

        $generation->usage = $usage;
        $generation->provider_cost_nanos = $providerCost;
        $generation->charge_nanos = $this->pricing->chargeFor($providerCost);
        $generation->image_url = is_string($remote['image_url'] ?? null) ? $remote['image_url'] : null;
        $generation->image_url_expires_at = $this->parseTime($remote['image_url_expires_at'] ?? null);
        $generation->completed_at = $this->parseTime($remote['completed_at'] ?? null) ?? CarbonImmutable::now();
        $generation->error_code = null;
        $generation->error_message = null;
    }

    private function fillFailure(Generation $generation, string $errorCode, string $message, ?CarbonImmutable $completedAt): void
    {
        $generation->error_code = $errorCode;
        $generation->error_message = $message;
        $generation->provider_cost_nanos = 0;
        $generation->charge_nanos = 0;
        $generation->completed_at = $completedAt ?? CarbonImmutable::now();
    }

    private function parseTime(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }
}
