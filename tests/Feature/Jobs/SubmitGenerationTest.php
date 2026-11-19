<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Services\Pixelforge\GenerationStateApplier;
use App\Services\Pixelforge\PixelforgeClient;
use Database\Factories\GenerationFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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

/**
 * @param  array<string, string>  $headers
 */
function fakeCreateError(int $status, string $code, array $headers = []): void
{
    Http::fake([
        'pixelforge.test/v1/generations' => Http::response(
            ['error' => ['type' => 'api_error', 'code' => $code, 'message' => 'Pixelforge says no']],
            $status,
            $headers,
        ),
    ]);
}

it('backs off exponentially on repeated overloads instead of hammering every two seconds', function () {
    $generation = Generation::factory()->create(['transient_failures' => 2]);
    fakeCreateError(503, 'overloaded', ['Retry-After' => '2']);

    $job = runSubmitJob($generation)->assertReleased();

    expect($job->job->releaseDelay)->toBeGreaterThanOrEqual(8)->toBeLessThanOrEqual(11)
        ->and($generation->fresh()->transient_failures)->toBe(3);
});

it('fails the generation at no charge once Pixelforge stays overloaded', function () {
    $generation = Generation::factory()->create(['prompt' => 'a cat [overloaded]', 'transient_failures' => 4]);
    fakeCreateError(503, 'overloaded', ['Retry-After' => '2']);

    runSubmitJob($generation)->assertNotReleased();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('overloaded')
        ->and($generation->charge_nanos)->toBe(0);
});

it('keeps waiting for a free concurrency slot however many times it has been released', function () {
    $generation = Generation::factory()->create();
    fakeCreateError(429, 'concurrency_limit_exceeded', ['Retry-After' => '5']);

    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    $job->job->attempts = 50;
    $job->handle(app(PixelforgeClient::class), app(GenerationStateApplier::class));

    $job->assertReleased();
    expect($job->job->releaseDelay)->toBeGreaterThanOrEqual(SubmitGeneration::MAX_BACKOFF_SECONDS)
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Pending)
        ->and($generation->fresh()->transient_failures)->toBe(0);
});

it('fails immediately at no charge when the daily limit is reached', function () {
    $generation = Generation::factory()->create();
    fakeCreateError(429, 'daily_limit_exceeded');

    runSubmitJob($generation)->assertNotReleased();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('daily_limit_exceeded')
        ->and($generation->charge_nanos)->toBe(0);
});

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

it('can be pushed onto a real queue', function () {
    $generation = Generation::factory()->create();

    Queue::connection('database')->push(new SubmitGeneration($generation));

    expect(DB::table('jobs')->count())->toBe(1);
});
