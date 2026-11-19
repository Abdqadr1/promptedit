<?php

namespace App\Services\Pixelforge;

use App\Models\ProviderUsageDay;
use Carbon\CarbonImmutable;

/**
 * Copies Pixelforge's per-UTC-day billing (GET /v1/usage) into provider_usage_days.
 *
 * The endpoint accepts at most 92 days per request, so longer ranges are fetched in chunks.
 * Rows are upserted by date, so syncing the same range again simply refreshes it.
 */
class UsageSync
{
    public const int MAX_DAYS_PER_REQUEST = 92;

    public function __construct(private readonly PixelforgeClient $client) {}

    /**
     * @return int Number of days stored.
     */
    public function sync(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $start = $start->utc()->startOfDay();
        $end = $end->utc()->startOfDay()->min(CarbonImmutable::now('UTC')->startOfDay());

        $stored = 0;

        for ($chunkStart = $start; $chunkStart->lte($end); $chunkStart = $chunkEnd->addDay()) {
            $chunkEnd = $chunkStart->addDays(self::MAX_DAYS_PER_REQUEST - 1)->min($end);

            $usage = $this->client->usage($chunkStart->toDateString(), $chunkEnd->toDateString());

            $rows = array_map(fn (array $day): array => [
                'date' => (string) $day['date'],
                'generations' => (int) ($day['generations'] ?? 0),
                'input_text_tokens' => (int) ($day['input_text_tokens'] ?? 0),
                'input_image_tokens' => (int) ($day['input_image_tokens'] ?? 0),
                'output_tokens' => (int) ($day['output_tokens'] ?? 0),
                'reasoning_tokens' => (int) ($day['reasoning_tokens'] ?? 0),
                'cost_nanos' => Pricing::decimalToNanos((string) $day['cost_usd']),
                'synced_at' => now(),
            ], $usage['data']);

            if ($rows !== []) {
                ProviderUsageDay::query()->upsert($rows, ['date'], [
                    'generations', 'input_text_tokens', 'input_image_tokens', 'output_tokens',
                    'reasoning_tokens', 'cost_nanos', 'synced_at',
                ]);
            }

            $stored += count($rows);
        }

        return $stored;
    }
}
