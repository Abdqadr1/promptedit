<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GenerationStatus;
use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\Pricing;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Spend, provider cost and profit by UTC day and by user, with our provider cost checked
 * against Pixelforge's own GET /v1/usage. Like Pixelforge, a generation counts on the UTC day
 * it completed.
 */
class ReportController extends Controller
{
    private const int MAX_RANGE_DAYS = 92;

    private const string AGGREGATE_COLUMNS = "sum(case when status = 'succeeded' then 1 else 0 end) as succeeded, "
        ."sum(case when status = 'failed' then 1 else 0 end) as failed, "
        .'coalesce(sum(charge_nanos), 0) as charged_nanos, '
        .'coalesce(sum(provider_cost_nanos), 0) as provider_cost_nanos';

    public function __invoke(Request $request, PixelforgeClient $client): Response
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        $end = CarbonImmutable::parse($validated['end_date'] ?? now()->utc()->toDateString(), 'UTC');
        $start = CarbonImmutable::parse($validated['start_date'] ?? $end->subDays(29)->toDateString(), 'UTC');

        if ($start->diffInDays($end) + 1 > self::MAX_RANGE_DAYS) {
            $start = $end->subDays(self::MAX_RANGE_DAYS - 1);
        }

        $completed = Generation::query()
            ->whereIn('status', [GenerationStatus::Succeeded, GenerationStatus::Failed])
            ->whereBetween('completed_at', [$start->startOfDay(), $end->endOfDay()]);

        $daily = (clone $completed)
            ->selectRaw('date(completed_at) as day')
            ->selectRaw(self::AGGREGATE_COLUMNS)
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $perUser = (clone $completed)
            ->select('user_id')
            ->selectRaw(self::AGGREGATE_COLUMNS)
            ->groupBy('user_id')
            ->orderByDesc('charged_nanos')
            ->get();

        $users = User::query()->whereIn('id', $perUser->pluck('user_id'))->get(['id', 'name', 'email'])->keyBy('id');

        [$providerUsage, $providerError] = $this->providerUsage($client, $start, $end);

        $days = collect();

        for ($day = $end; $day->gte($start); $day = $day->subDay()) {
            $date = $day->toDateString();
            $row = $daily->get($date);
            $providerNanos = isset($providerUsage[$date]) ? Pricing::decimalToNanos($providerUsage[$date]) : null;

            $days->push([
                'date' => $date,
                ...$this->formatRow($row),
                'provider_reported_cost' => $providerNanos === null ? null : Pricing::formatNanos($providerNanos, 9),
                'matches_provider' => $providerNanos === null ? null : $providerNanos === (int) ($row->provider_cost_nanos ?? 0),
            ]);
        }

        return Inertia::render('admin/Report', [
            'filters' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
            'days' => $days,
            'users' => $perUser->map(fn (object $row): array => [
                'user_id' => $row->user_id,
                'name' => $users->get($row->user_id)?->name,
                'email' => $users->get($row->user_id)?->email,
                ...$this->formatRow($row),
            ]),
            'totals' => $this->formatTotals($daily),
            'provider_error' => $providerError,
        ]);
    }

    /**
     * @return array<string, int|string>
     */
    private function formatRow(?object $row): array
    {
        $charged = (int) ($row->charged_nanos ?? 0);
        $cost = (int) ($row->provider_cost_nanos ?? 0);

        return [
            'succeeded' => (int) ($row->succeeded ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'charged' => Pricing::formatNanos($charged),
            'provider_cost' => Pricing::formatNanos($cost, 9),
            'profit' => Pricing::formatNanos($charged - $cost),
        ];
    }

    /**
     * @param  Collection<string, mixed>  $daily
     * @return array<string, int|string>
     */
    private function formatTotals(Collection $daily): array
    {
        return $this->formatRow((object) [
            'succeeded' => $daily->sum('succeeded'),
            'failed' => $daily->sum('failed'),
            'charged_nanos' => $daily->sum('charged_nanos'),
            'provider_cost_nanos' => $daily->sum('provider_cost_nanos'),
        ]);
    }

    /**
     * Pixelforge's cost per UTC day, as exact decimal strings keyed by date.
     *
     * @return array{0: array<string, string>, 1: string|null}
     */
    private function providerUsage(PixelforgeClient $client, CarbonImmutable $start, CarbonImmutable $end): array
    {
        try {
            $usage = Cache::remember(
                "pixelforge.usage.{$start->toDateString()}.{$end->toDateString()}",
                now()->addMinutes(5),
                fn (): array => $client->usage($start->toDateString(), $end->toDateString()),
            );
        } catch (Throwable $exception) {
            return [[], 'Could not load Pixelforge usage: '.$exception->getMessage()];
        }

        return [
            collect($usage['data'])->mapWithKeys(fn (array $day): array => [(string) $day['date'] => (string) $day['cost_usd']])->all(),
            null,
        ];
    }
}
