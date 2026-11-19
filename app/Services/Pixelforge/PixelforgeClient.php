<?php

namespace App\Services\Pixelforge;

use App\Models\Generation;
use App\Services\Pixelforge\Exceptions\RejectedException;
use App\Services\Pixelforge\Exceptions\RetryableException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Http::pixelforge() macro that maps API errors to typed exceptions.
 */
class PixelforgeClient
{
    /**
     * Submits a generation. The local ULID is the idempotency key, so retries never create duplicates.
     *
     * @return array<string, mixed> The Pixelforge generation object.
     */
    public function createGeneration(Generation $generation): array
    {
        return $this->send(fn (PendingRequest $request) => $request
            ->withHeaders(['Idempotency-Key' => $generation->id])
            ->post('/generations', array_filter([
                'prompt' => $generation->prompt,
                'size' => $generation->size->value,
                'quality' => $generation->quality->value,
                'reference_images' => $generation->reference_images ?: null,
                'metadata' => ['local_id' => $generation->id],
            ], fn (mixed $value): bool => $value !== null)));
    }

    /**
     * @return array<string, mixed>
     */
    public function getGeneration(string $providerId): array
    {
        return $this->send(fn (PendingRequest $request) => $request->get('/generations/'.rawurlencode($providerId)));
    }

    /**
     * @return array{rates: array<string, string>, tokens: array<string, mixed>}
     */
    public function pricing(): array
    {
        /** @var array{rates: array<string, string>, tokens: array<string, mixed>} */
        return $this->send(fn (PendingRequest $request) => $request->get('/pricing'));
    }

    /**
     * Per-UTC-day provider billing for this API key.
     *
     * @return array{data: list<array<string, mixed>>, total: array<string, mixed>}
     */
    public function usage(string $startDate, string $endDate): array
    {
        /** @var array{data: list<array<string, mixed>>, total: array<string, mixed>} */
        return $this->send(fn (PendingRequest $request) => $request->get('/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    /**
     * @param  Closure(PendingRequest): Response  $callback
     * @return array<string, mixed>
     */
    private function send(Closure $callback): array
    {
        try {
            $response = $callback(Http::pixelforge());
        } catch (ConnectionException $exception) {
            throw new RetryableException('Could not reach Pixelforge: '.$exception->getMessage(), 'connection_error', null, $exception);
        }

        if ($response->successful()) {
            return (array) $response->json();
        }

        $errorCode = (string) ($response->json('error.code') ?? 'http_'.$response->status());
        $message = (string) ($response->json('error.message') ?? 'Pixelforge returned HTTP '.$response->status());

        if ($response->status() === 429 || $response->serverError()) {
            $retryAfter = $response->header('Retry-After');

            throw new RetryableException($message, $errorCode, is_numeric($retryAfter) ? (int) $retryAfter : null);
        }

        throw new RejectedException($message, $errorCode, $response->status());
    }
}
