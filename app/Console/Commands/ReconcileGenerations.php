<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\Exceptions\RejectedException;
use App\Services\Pixelforge\Exceptions\RetryableException;
use App\Services\Pixelforge\GenerationStateApplier;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Safety net for lost or delayed webhooks: polls Pixelforge for generations that have been
 * in flight too long and applies whatever state it reports.
 */
#[Signature('generations:reconcile {--older-than=120 : Only check generations not synced for this many seconds} {--limit=50}')]
#[Description('Poll Pixelforge for in-flight generations whose webhooks have not arrived')]
class ReconcileGenerations extends Command
{
    public function handle(PixelforgeClient $client, GenerationStateApplier $applier): int
    {
        $staleBefore = now()->subSeconds((int) $this->option('older-than'));

        $generations = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where(fn ($query) => $query
                ->where('last_synced_at', '<', $staleBefore)
                ->orWhereNull('last_synced_at'))
            ->oldest('last_synced_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $updated = 0;

        foreach ($generations as $generation) {
            try {
                $remote = $client->getGeneration((string) $generation->provider_id);
            } catch (RetryableException $exception) {
                $this->warn("Stopping early: {$exception->getMessage()}");

                break;
            } catch (RejectedException $exception) {
                $this->warn("Skipping {$generation->id}: {$exception->getMessage()}");

                continue;
            }

            $before = $generation->status;

            if ($applier->apply($generation, $remote)->status !== $before) {
                $updated++;
            }
        }

        $this->info("Checked {$generations->count()} generation(s), {$updated} updated.");

        return self::SUCCESS;
    }
}
