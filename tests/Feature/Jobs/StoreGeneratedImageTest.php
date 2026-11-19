<?php

use App\Jobs\StoreGeneratedImage;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('copies the image to our storage so it outlives the signed url', function () {
    Storage::fake('local');
    $generation = Generation::factory()->succeeded()->create(['image_url' => 'https://cdn.example.test/image.png']);
    Http::fake(['cdn.example.test/image.png' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png'])]);

    (new StoreGeneratedImage($generation))->handle();

    Storage::disk('local')->assertExists("generations/{$generation->id}.png");
    expect($generation->fresh()->image_path)->toBe("generations/{$generation->id}.png");
});

it('still stores the image when the url works past its stated expiry', function () {
    Storage::fake('local');
    $generation = Generation::factory()->succeeded()->create([
        'image_url' => 'https://cdn.example.test/late.png',
        'image_url_expires_at' => now()->subMinute(),
    ]);
    Http::fake(['cdn.example.test/late.png' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png'])]);

    (new StoreGeneratedImage($generation))->handle();

    Storage::disk('local')->assertExists("generations/{$generation->id}.png");
});

it('gives up without retrying when the url is really gone', function (int $status) {
    Storage::fake('local');
    $generation = Generation::factory()->succeeded()->create(['image_url' => 'https://cdn.example.test/gone.png']);
    Http::fake(['cdn.example.test/gone.png' => Http::response('Expired', $status)]);

    (new StoreGeneratedImage($generation))->handle();

    expect($generation->fresh()->image_path)->toBeNull();
})->with([403, 404, 410]);

it('fails so the queue retries when the response is not an image', function () {
    Storage::fake('local');
    $generation = Generation::factory()->succeeded()->create(['image_url' => 'https://cdn.example.test/odd.png']);
    Http::fake(['cdn.example.test/odd.png' => Http::response('<html>maintenance</html>', 200, ['Content-Type' => 'text/html'])]);

    (new StoreGeneratedImage($generation))->handle();
})->throws(RuntimeException::class);
