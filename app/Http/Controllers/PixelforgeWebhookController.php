<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\GenerationStateApplier;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Receives Pixelforge webhooks. Delivery is at-least-once and unordered, so every event is
 * verified, deduplicated by event id, and applied through the forward-only state machine.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookSignature $signature, GenerationStateApplier $applier): JsonResponse
    {
        $rawBody = $request->getContent();

        if (! $signature->isValid($rawBody, $request->header('Pixelforge-Signature'))) {
            return response()->json(['error' => 'invalid_signature'], 400);
        }

        /** @var array<string, mixed>|null $event */
        $event = json_decode($rawBody, true);
        $eventId = $event['id'] ?? null;
        $type = $event['type'] ?? null;

        if (! is_string($eventId) || ! is_string($type)) {
            return response()->json(['error' => 'malformed_event'], 400);
        }

        if ($type === 'ping') {
            return response()->json(['received' => true]);
        }

        /** @var array<string, mixed> $remote */
        $remote = (array) ($event['data']['object'] ?? []);
        $generation = $this->resolveGeneration($remote);

        if ($generation === null) {
            Log::warning('Pixelforge webhook for unknown generation.', ['event' => $eventId, 'generation' => $remote['id'] ?? null]);
        }

        // Recording the event and applying it commit together: if applying fails, the event is
        // not marked as seen and Pixelforge's retry will be processed normally.
        $isDuplicate = DB::transaction(function () use ($eventId, $type, $generation, $rawBody, $remote, $applier): bool {
            $inserted = WebhookEvent::query()->insertOrIgnore([
                'id' => $eventId,
                'type' => $type,
                'generation_id' => $generation?->id,
                'payload' => $rawBody,
                'received_at' => now(),
            ]);

            if ($inserted === 0) {
                return true;
            }

            if ($generation !== null) {
                $applier->apply($generation, $remote);
            }

            return false;
        });

        return response()->json(['received' => true, 'duplicate' => $isDuplicate]);
    }

    /**
     * Matches by Pixelforge id, falling back to our own id in metadata because a webhook can
     * arrive before the create request has returned the Pixelforge id to us.
     *
     * @param  array<string, mixed>  $remote
     */
    private function resolveGeneration(array $remote): ?Generation
    {
        $providerId = $remote['id'] ?? null;
        $localId = $remote['metadata']['local_id'] ?? null;

        if (is_string($providerId) && ($generation = Generation::query()->firstWhere('provider_id', $providerId))) {
            return $generation;
        }

        return is_string($localId) ? Generation::query()->find($localId) : null;
    }
}
