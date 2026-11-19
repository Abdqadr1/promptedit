<?php

use App\Enums\GenerationStatus;
use App\Jobs\StoreGeneratedImage;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\WebhookSignature;
use Database\Factories\GenerationFactory;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['services.pixelforge.webhook_secret' => 'whsec_test']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function remoteGeneration(Generation $generation, string $status, array $overrides = []): array
{
    return array_merge([
        'id' => $generation->provider_id,
        'object' => 'generation',
        'status' => $status,
        'metadata' => ['local_id' => $generation->id],
        'rates' => GenerationFactory::rates(),
        'usage' => null,
        'image_url' => null,
        'image_url_expires_at' => null,
        'error' => null,
        'completed_at' => null,
    ], $overrides);
}

/**
 * Usage totalling exactly $0.07 provider cost (charge $0.0875).
 *
 * @return array<string, mixed>
 */
function succeededOverrides(): array
{
    return [
        'usage' => [
            'input_text_tokens' => 0,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 2080,
            'total_tokens' => 3180,
        ],
        'image_url' => 'https://cdn.example.test/image.png',
        'image_url_expires_at' => now()->addDay()->toIso8601String(),
        'completed_at' => now()->toIso8601String(),
    ];
}

/**
 * @param  array<string, mixed>  $object
 */
function postWebhook(string $eventId, string $type, array $object, ?string $signature = null): TestResponse
{
    $body = json_encode([
        'id' => $eventId,
        'object' => 'event',
        'type' => $type,
        'created_at' => now()->toIso8601String(),
        'data' => ['object' => $object],
    ]);

    $timestamp = time();

    return test()->call('POST', route('webhooks.pixelforge'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $signature ?? 't='.$timestamp.',v1='.WebhookSignature::sign('whsec_test', $timestamp, $body),
    ], content: $body);
}

it('rejects an event with an invalid signature with 400', function () {
    $generation = Generation::factory()->queued()->create();

    postWebhook('evt_1', 'generation.processing', remoteGeneration($generation, 'processing'), 't='.time().',v1=bad')
        ->assertStatus(400);

    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued)
        ->and(WebhookEvent::query()->count())->toBe(0);
});

it('records the exact charge when a generation succeeds', function () {
    Queue::fake([StoreGeneratedImage::class]);
    $generation = Generation::factory()->queued()->create();

    postWebhook('evt_1', 'generation.succeeded', remoteGeneration($generation, 'succeeded', succeededOverrides()))
        ->assertOk();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost_nanos)->toBe(70_000_000)
        ->and($generation->charge_nanos)->toBe(87_500_000)
        ->and($generation->image_url)->toBe('https://cdn.example.test/image.png');
    Queue::assertPushed(StoreGeneratedImage::class, fn (StoreGeneratedImage $job) => $job->generation->is($generation));
});

it('processes a duplicate event only once', function () {
    Queue::fake([StoreGeneratedImage::class]);
    $generation = Generation::factory()->queued()->create();
    $object = remoteGeneration($generation, 'succeeded', succeededOverrides());

    postWebhook('evt_1', 'generation.succeeded', $object)->assertOk()->assertJson(['duplicate' => false]);
    postWebhook('evt_1', 'generation.succeeded', $object)->assertOk()->assertJson(['duplicate' => true]);

    expect(WebhookEvent::query()->count())->toBe(1);
    Queue::assertPushed(StoreGeneratedImage::class, 1);
});

it('does not move a succeeded generation back to processing when events arrive out of order', function () {
    Queue::fake([StoreGeneratedImage::class]);
    $generation = Generation::factory()->queued()->create();

    postWebhook('evt_2', 'generation.succeeded', remoteGeneration($generation, 'succeeded', succeededOverrides()));
    postWebhook('evt_1', 'generation.processing', remoteGeneration($generation, 'processing'))->assertOk();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charge_nanos)->toBe(87_500_000);
});

it('ignores a failure event for a generation that already succeeded', function () {
    $generation = Generation::factory()->succeeded(70_000_000)->create();

    postWebhook('evt_9', 'generation.failed', remoteGeneration($generation, 'failed', [
        'error' => ['code' => 'internal_error', 'message' => 'boom'],
    ]))->assertOk();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charge_nanos)->toBe(87_500_000)
        ->and($generation->error_code)->toBeNull();
});

it('charges nothing for a failed generation', function () {
    $generation = Generation::factory()->queued()->create();

    postWebhook('evt_1', 'generation.failed', remoteGeneration($generation, 'failed', [
        'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'],
        'completed_at' => now()->toIso8601String(),
    ]))->assertOk();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charge_nanos)->toBe(0)
        ->and($generation->provider_cost_nanos)->toBe(0)
        ->and($generation->error_code)->toBe('generation_failed');
});

it('matches a webhook that arrives before the create response by local id', function () {
    $generation = Generation::factory()->create();

    postWebhook('evt_1', 'generation.processing', [
        ...remoteGeneration($generation, 'processing'),
        'id' => 'gen_early',
    ])->assertOk();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Processing)
        ->and($generation->provider_id)->toBe('gen_early')
        ->and($generation->rates)->toBe(GenerationFactory::rates());
});

it('acknowledges events for unknown generations without failing', function () {
    postWebhook('evt_1', 'generation.processing', ['id' => 'gen_unknown', 'status' => 'processing'])
        ->assertOk();

    expect(WebhookEvent::query()->find('evt_1')?->generation_id)->toBeNull();
});

it('acknowledges ping events', function () {
    postWebhook('evt_ping', 'ping', [])->assertOk();

    expect(WebhookEvent::query()->count())->toBe(0);
});
