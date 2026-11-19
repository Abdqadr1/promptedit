<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Services\Pixelforge\GenerationStateApplier;
use App\Services\Pixelforge\PixelforgeClient;
use Database\Factories\GenerationFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.pixelforge.base_url' => 'https://pixelforge.test/v1']);
    Http::preventStrayRequests();
});

function runSubmitJob(Generation $generation): SubmitGeneration
{
    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    $job->handle(app(PixelforgeClient::class), app(GenerationStateApplier::class));

    return $job;
}

it('submits the generation with its id as the idempotency key and stores the provider id', function () {
    $generation = Generation::factory()->create(['reference_images' => ['https://example.test/ref.png']]);

    Http::fake([
        'pixelforge.test/v1/generations' => Http::response([
            'id' => 'gen_123',
            'status' => 'queued',
            'rates' => GenerationFactory::rates(),
        ], 202),
    ]);

    runSubmitJob($generation)->assertNotReleased();

    Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $generation->id)
        && $request['metadata'] === ['local_id' => $generation->id]
        && $request['reference_images'] === ['https://example.test/ref.png']);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_id)->toBe('gen_123')
        ->and($generation->submitted_at)->not->toBeNull();
});

it('releases the job honoring Retry-After when Pixelforge is busy', function (int $status, string $code, int $retryAfter) {
    $generation = Generation::factory()->create();

    Http::fake([
        'pixelforge.test/v1/generations' => Http::response(
            ['error' => ['type' => 'rate_limit_error', 'code' => $code, 'message' => 'Slow down']],
            $status,
            ['Retry-After' => (string) $retryAfter],
        ),
    ]);

    $job = runSubmitJob($generation)->assertReleased();

    expect($job->job->releaseDelay)->toBeGreaterThanOrEqual($retryAfter)->toBeLessThanOrEqual($retryAfter + 3)
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Pending);
})->with([
    'concurrency limit' => [429, 'concurrency_limit_exceeded', 5],
    'rate limit' => [429, 'rate_limit_exceeded', 20],
    'overloaded' => [503, 'overloaded', 2],
]);

it('marks the generation failed at no charge when Pixelforge rejects it', function () {
    $generation = Generation::factory()->create(['prompt' => 'a cat [blocked]']);

    Http::fake([
        'pixelforge.test/v1/generations' => Http::response(
            ['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Blocked by policy.']],
            400,
        ),
    ]);

    runSubmitJob($generation)->assertNotReleased();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('content_policy_violation')
        ->and($generation->charge_nanos)->toBe(0);
});

it('does not resubmit a generation a webhook already advanced', function () {
    $generation = Generation::factory()->queued()->create();

    runSubmitJob($generation);

    Http::assertNothingSent();
});

it('marks the generation failed at no charge when it can never be submitted', function () {
    $generation = Generation::factory()->create();

    (new SubmitGeneration($generation))->failed(new RuntimeException('gave up'));

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('submission_failed')
        ->and($generation->charge_nanos)->toBe(0);
});
