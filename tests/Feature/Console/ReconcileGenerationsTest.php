<?php

use App\Enums\GenerationStatus;
use App\Jobs\StoreGeneratedImage;
use App\Models\Generation;
use Database\Factories\GenerationFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.pixelforge.base_url' => 'https://pixelforge.test/v1']);
    Http::preventStrayRequests();
});

it('applies the state of a generation whose webhooks never arrived', function () {
    Queue::fake([StoreGeneratedImage::class]);
    $stale = Generation::factory()->queued()->create(['provider_id' => 'gen_stale', 'last_synced_at' => now()->subMinutes(5)]);
    $fresh = Generation::factory()->queued()->create(['provider_id' => 'gen_fresh', 'last_synced_at' => now()]);

    Http::fake([
        'pixelforge.test/v1/generations/gen_stale' => Http::response([
            'id' => 'gen_stale',
            'status' => 'succeeded',
            'rates' => GenerationFactory::rates(),
            'usage' => ['input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 2080],
            'image_url' => 'https://cdn.example.test/image.png',
            'completed_at' => now()->toIso8601String(),
        ]),
    ]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    expect($stale->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($stale->fresh()->charge_nanos)->toBe(87_500_000)
        ->and($fresh->fresh()->status)->toBe(GenerationStatus::Queued);
    Http::assertSentCount(1);
});
