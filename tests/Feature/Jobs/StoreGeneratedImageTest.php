<?php

use App\Jobs\StoreGeneratedImage;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('copies the image to our storage so it outlives the signed url', function () {
    Storage::fake('public');
    $generation = Generation::factory()->succeeded()->create(['image_url' => 'https://cdn.example.test/image.png']);
    Http::fake(['cdn.example.test/image.png' => Http::response('png-bytes')]);

    (new StoreGeneratedImage($generation))->handle();

    Storage::disk('public')->assertExists("generations/{$generation->id}.png");
    expect($generation->fresh()->image_path)->toBe("generations/{$generation->id}.png");
});

it('does not try to download an image whose url has already expired', function () {
    Storage::fake('public');
    $generation = Generation::factory()->succeeded()->create(['image_url_expires_at' => now()->subMinute()]);

    (new StoreGeneratedImage($generation))->handle();

    Http::assertNothingSent();
    expect($generation->fresh()->image_path)->toBeNull();
});
