<?php

namespace App\Jobs;

use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Copies a finished image to our own storage, because Pixelforge's signed URLs expire after 24 hours.
 */
class StoreGeneratedImage implements ShouldQueue
{
    use Queueable;

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

        if ($generation->image_url_expires_at?->isPast()) {
            return;
        }

        $image = Http::timeout(30)->get($generation->image_url)->throw()->body();

        $path = "generations/{$generation->id}.png";

        Storage::disk(config('services.pixelforge.image_disk'))->put($path, $image);

        $generation->forceFill(['image_path' => $path])->save();
    }
}
