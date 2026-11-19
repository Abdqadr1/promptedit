<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GenerationStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SyncProviderUsage;
use App\Models\Generation;
use App\Models\ProviderUsageDay;
use App\Models\User;
use App\Services\Pixelforge\Pricing;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Spend, provider cost and profit by UTC day and by user, for any date range. Our provider cost
 * is checked against Pixelforge's own per-day usage, which is synced into provider_usage_days
 * (see UsageSync), so the report never calls Pixelforge itself. Like Pixelforge, a generation
 * counts on the UTC day it completed.
 */
class ReportController extends Controller
{
    private const int DAYS_PER_PAGE = 10;

    private const int USERS_PER_PAGE = 10;

    private const string AGGREGATE_COLUMNS = "sum(case when status = 'succeeded' then 1 else 0 end) as succeeded, "
        ."sum(case when status = 'failed' then 1 else 0 end) as failed, "
        .'coalesce(sum(charge_nanos), 0) as charged_nanos, '
        .'coalesce(sum(provider_cost_nanos), 0) as provider_cost_nanos';

    public function __invoke(Request $request): Response
    {
        [$start, $end] = $this->dateRange($request);

        $totalDays = (int) $start->diffInDays($end) + 1;
        $daysPage = max(1, $request->integer('days_page', 1));

        $ourTotals = $this->completedBetween($start, $end)->selectRaw(self::AGGREGATE_COLUMNS)->toBase()->first();

        $providerTotals = ProviderUsageDay::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('count(*) as synced_days, coalesce(sum(cost_nanos), 0) as cost_nanos')
            ->toBase()
            ->first();

        $unsyncedDays = $totalDays - (int) ($providerTotals->synced_days ?? 0);
        $staleDays = $this->staleProviderDays($start, $end)->count();
        $providerTotalNanos = (int) ($providerTotals->cost_nanos ?? 0);

        $perUser = $this->completedBetween($start, $end)
            ->select('user_id')
            ->selectRaw(self::AGGREGATE_COLUMNS)
            ->groupBy('user_id')
            ->orderByDesc('charged_nanos')
            ->orderBy('user_id')
            ->paginate(self::USERS_PER_PAGE, pageName: 'users_page')
            ->withQueryString();

        $users = User::query()
            ->whereIn('id', $perUser->getCollection()->pluck('user_id'))
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        return Inertia::render('admin/Report', [
            'filters' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
            'days' => (new LengthAwarePaginator(
                $this->dayRows($start, $end, $daysPage),
                $totalDays,
                self::DAYS_PER_PAGE,
                $daysPage,
                ['path' => $request->url(), 'pageName' => 'days_page'],
            ))->withQueryString(),
            'users' => $perUser->through(fn (object $row): array => [
                'user_id' => $row->user_id,
                'name' => $users->get($row->user_id)?->name,
                'email' => $users->get($row->user_id)?->email,
                ...$this->formatRow($row),
            ]),
            'totals' => [
                ...$this->formatRow($ourTotals),
                'provider_reported_cost' => Pricing::formatNanos($providerTotalNanos, 9),
                'matches_provider' => $unsyncedDays === 0 && $staleDays === 0
                    ? $providerTotalNanos === (int) ($ourTotals->provider_cost_nanos ?? 0)
                    : null,
                'unsynced_days' => $unsyncedDays,
                'stale_days' => $staleDays,
            ],
        ]);
    }

    /**
     * Queue a sync of Pixelforge's usage for the selected range.
     */
    public function sync(Request $request): RedirectResponse
    {
        [$start, $end] = $this->dateRange($request);

        SyncProviderUsage::dispatch($start->toDateString(), $end->toDateString());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Syncing Pixelforge usage. Refresh in a moment to see it.'),
        ]);

        return back();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function dateRange(Request $request): array
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date', 'before_or_equal:today'],
        ]);

        $end = CarbonImmutable::parse($validated['end_date'] ?? now()->utc()->toDateString(), 'UTC');
        $start = CarbonImmutable::parse($validated['start_date'] ?? $end->subDays(29)->toDateString(), 'UTC');

        return [$start->min($end), $end];
    }

    /**
     * Rows for one page of days, newest first. Only that page's dates are queried, so the cost
     * of a page does not grow with the length of the range.
     *
     * @return list<array<string, mixed>>
     */
    private function dayRows(CarbonImmutable $start, CarbonImmutable $end, int $page): array
    {
        $windowEnd = $end->subDays(($page - 1) * self::DAYS_PER_PAGE);

        if ($windowEnd->lt($start)) {
            return [];
        }

        $windowStart = $windowEnd->subDays(self::DAYS_PER_PAGE - 1)->max($start);

        $ours = $this->completedBetween($windowStart, $windowEnd)
            ->selectRaw('date(completed_at) as day')
            ->selectRaw(self::AGGREGATE_COLUMNS)
            ->groupBy('day')
            ->toBase()
            ->get()
            ->keyBy('day');

        $provider = ProviderUsageDay::query()
            ->whereBetween('date', [$windowStart->toDateString(), $windowEnd->toDateString()])
            ->get()
            ->keyBy('date');

        $staleDates = $this->staleProviderDays($windowStart, $windowEnd)->pluck('date')->flip();

        $rows = [];

        for ($day = $windowEnd; $day->gte($windowStart); $day = $day->subDay()) {
            $date = $day->toDateString();
            $row = $ours->get($date);
            $usage = $provider->get($date);
            $isStale = $staleDates->has($date);

            $rows[] = [
                'date' => $date,
                ...$this->formatRow($row),
                'provider_reported_cost' => $usage === null ? null : Pricing::formatNanos($usage->cost_nanos, 9),
                'provider_synced_at' => $usage?->synced_at->toIso8601String(),
                'provider_stale' => $isStale,
                'matches_provider' => $usage === null || $isStale
                    ? null
                    : $usage->cost_nanos === (int) ($row->provider_cost_nanos ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Synced days whose snapshot is older than one of our generations completed that day, so the
     * stored Pixelforge figure cannot be compared yet (it will catch up on the next sync).
     *
     * @return Builder<ProviderUsageDay>
     */
    private function staleProviderDays(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return ProviderUsageDay::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('generations')
                ->whereIn('generations.status', [GenerationStatus::Succeeded, GenerationStatus::Failed])
                ->whereRaw('date(generations.completed_at) = provider_usage_days.date')
                ->whereColumn('generations.completed_at', '>', 'provider_usage_days.synced_at'));
    }

    /**
     * @return Builder<Generation>
     */
    private function completedBetween(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return Generation::query()
            ->whereIn('status', [GenerationStatus::Succeeded, GenerationStatus::Failed])
            ->whereBetween('completed_at', [$start->startOfDay(), $end->endOfDay()]);
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
}
