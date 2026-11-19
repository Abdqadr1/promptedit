<?php

namespace App\Jobs;

use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Copies a finished image to our own storage, because Pixelforge's signed URLs expire after 24 hours.
 */
class StoreGeneratedImage implements ShouldQueue
{
    use Queueable;

    /**
     * Responses meaning the signed URL is really dead (expired or removed); retrying cannot help.
     */
    private const array GONE_STATUSES = [401, 403, 404, 410];

    public int $tries = 5;

    public function __construct(public Generation $generation)
    {
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function handle(): void
    {
        $generation = $this->generation->fresh();

        if ($generation === null || $generation->image_path !== null || $generation->image_url === null) {
            return;
        }

        // Ask the URL itself rather than trusting image_url_expires_at: Pixelforge may keep serving
        // an image past its stated expiry, and that is our last chance to keep a copy.
        $response = Http::timeout(30)->get($generation->image_url);

        if (in_array($response->status(), self::GONE_STATUSES, true)) {
            Log::info('Pixelforge image is no longer available; not storing a copy.', [
                'generation' => $generation->id,
                'status' => $response->status(),
            ]);

            return;
        }

        $response->throw();

        if (! str_starts_with((string) $response->header('Content-Type'), 'image/') || $response->body() === '') {
            throw new RuntimeException("Pixelforge did not return an image for generation {$generation->id}.");
        }

        $path = "generations/{$generation->id}.png";

        Storage::disk(config('services.pixelforge.image_disk'))->put($path, $response->body());

        $generation->forceFill(['image_path' => $path])->save();
    }
}
