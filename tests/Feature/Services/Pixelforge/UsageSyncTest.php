<?php

use App\Models\ProviderUsageDay;
use App\Services\Pixelforge\UsageSync;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.pixelforge.base_url' => 'https://pixelforge.test/v1']);
    Http::preventStrayRequests();
    $this->travelTo('2026-11-19 12:00:00');
});

/**
 * Fakes /v1/usage, answering each request with one zero-cost row per requested day, except
 * the given costs.
 *
 * @param  array<string, string>  $costs
 */
function fakeUsage(array $costs = []): void
{
    Http::fake([
        'pixelforge.test/v1/usage*' => function (Request $request) use ($costs) {
            $days = [];

            for ($day = CarbonImmutable::parse($request['start_date']); $day->lte(CarbonImmutable::parse($request['end_date'])); $day = $day->addDay()) {
                $date = $day->toDateString();
                $days[] = [
                    'date' => $date,
                    'generations' => isset($costs[$date]) ? 1 : 0,
                    'input_text_tokens' => 0,
                    'input_image_tokens' => 0,
                    'output_tokens' => 0,
                    'reasoning_tokens' => 0,
                    'cost_usd' => $costs[$date] ?? '0.000000000',
                ];
            }

            return Http::response(['object' => 'list', 'data' => $days, 'total' => []]);
        },
    ]);
}

it('fetches long ranges in chunks of at most 92 days', function () {
    fakeUsage();

    $stored = app(UsageSync::class)->sync(CarbonImmutable::parse('2026-05-04'), CarbonImmutable::parse('2026-11-19'));

    expect($stored)->toBe(200)
        ->and(ProviderUsageDay::query()->count())->toBe(200);
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => $request['start_date'] === '2026-05-04' && $request['end_date'] === '2026-08-03');
    Http::assertSent(fn (Request $request) => $request['start_date'] === '2026-08-04' && $request['end_date'] === '2026-11-03');
    Http::assertSent(fn (Request $request) => $request['start_date'] === '2026-11-04' && $request['end_date'] === '2026-11-19');
});

it('stores the exact cost and refreshes a day when synced again', function () {
    ProviderUsageDay::factory()->create(['date' => '2026-11-19', 'cost_nanos' => 70_000_000]);
    fakeUsage(['2026-11-19' => '0.109000000']);
    app(UsageSync::class)->sync(CarbonImmutable::parse('2026-11-19'), CarbonImmutable::parse('2026-11-19'));

    expect(ProviderUsageDay::query()->count())->toBe(1)
        ->and(ProviderUsageDay::query()->sole()->cost_nanos)->toBe(109_000_000);
});

it('never asks for days after today', function () {
    fakeUsage();

    app(UsageSync::class)->sync(CarbonImmutable::parse('2026-11-18'), CarbonImmutable::parse('2026-12-31'));

    Http::assertSent(fn (Request $request) => $request['end_date'] === '2026-11-19');
});
