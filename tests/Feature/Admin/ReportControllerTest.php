<?php

use App\Jobs\SyncProviderUsage;
use App\Models\Generation;
use App\Models\ProviderUsageDay;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo('2026-11-19 12:00:00');
});

it('forbids non-admin users with 403', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.report'))
        ->assertForbidden();
});

it('reports spend, provider cost and profit and checks them against stored Pixelforge usage', function () {
    $customer = User::factory()->create(['name' => 'Customer']);
    Generation::factory()->for($customer)->succeeded(70_000_000)->create(['completed_at' => '2026-11-19 08:00:00']);
    Generation::factory()->for($customer)->succeeded(30_000_000)->create(['completed_at' => '2026-11-19 09:00:00']);
    Generation::factory()->for($customer)->failed()->create(['completed_at' => '2026-11-19 10:00:00']);
    Generation::factory()->for($customer)->succeeded(10_000_000)->create(['completed_at' => '2026-11-18 23:59:59']);
    ProviderUsageDay::factory()->create(['date' => '2026-11-19', 'cost_nanos' => 100_000_000]);
    ProviderUsageDay::factory()->create(['date' => '2026-11-18', 'cost_nanos' => 9_000_000]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', ['start_date' => '2026-11-18', 'end_date' => '2026-11-19']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Report')
            ->where('totals.charged', '$0.1375')
            ->where('totals.provider_cost', '$0.110000000')
            ->where('totals.profit', '$0.0275')
            ->where('totals.provider_reported_cost', '$0.109000000')
            ->where('totals.matches_provider', false)
            ->where('totals.unsynced_days', 0)
            ->where('days.data.0.date', '2026-11-19')
            ->where('days.data.0.succeeded', 2)
            ->where('days.data.0.failed', 1)
            ->where('days.data.0.charged', '$0.1250')
            ->where('days.data.0.matches_provider', true)
            ->where('days.data.1.date', '2026-11-18')
            ->where('days.data.1.matches_provider', false)
            ->where('users.data.0.name', 'Customer')
            ->where('users.data.0.profit', '$0.0275'));

    Http::assertNothingSent();
});

it('accepts ranges longer than 92 days and pages through them', function () {
    Generation::factory()->succeeded(30_000_000)->create(['completed_at' => '2026-05-04 08:00:00']);
    Generation::factory()->succeeded(70_000_000)->create(['completed_at' => '2026-11-19 08:00:00']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', ['start_date' => '2026-05-04', 'end_date' => '2026-11-19', 'days_page' => 20]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('days.total', 200)
            ->where('days.last_page', 20)
            ->has('days.data', 10)
            ->where('days.data.0.date', '2026-05-13')
            ->where('days.data.9.date', '2026-05-04')
            ->where('days.data.9.charged', '$0.0375')
            ->where('totals.charged', '$0.1250'));
});

it('flags days that have not been synced from Pixelforge', function () {
    ProviderUsageDay::factory()->create(['date' => '2026-11-19']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', ['start_date' => '2026-11-17', 'end_date' => '2026-11-19']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.unsynced_days', 2)
            ->where('totals.matches_provider', null)
            ->where('days.data.0.provider_reported_cost', '$0.000000000')
            ->where('days.data.1.provider_reported_cost', null));
});

it('does not flag a mismatch for a day with generations finished after its last sync', function () {
    Generation::factory()->succeeded(70_000_000)->create(['completed_at' => '2026-11-19 08:00:00']);
    Generation::factory()->succeeded(30_000_000)->create(['completed_at' => '2026-11-19 11:30:00']);
    ProviderUsageDay::factory()->create([
        'date' => '2026-11-19',
        'cost_nanos' => 70_000_000,
        'synced_at' => '2026-11-19 11:00:00',
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', ['start_date' => '2026-11-19', 'end_date' => '2026-11-19']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('days.data.0.provider_stale', true)
            ->where('days.data.0.matches_provider', null)
            ->where('days.data.0.provider_reported_cost', '$0.070000000')
            ->where('totals.stale_days', 1)
            ->where('totals.matches_provider', null));
});

it('compares a day again once it has been synced after its last generation', function () {
    Generation::factory()->succeeded(70_000_000)->create(['completed_at' => '2026-11-19 11:30:00']);
    ProviderUsageDay::factory()->create([
        'date' => '2026-11-19',
        'cost_nanos' => 70_000_000,
        'synced_at' => '2026-11-19 11:40:00',
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', ['start_date' => '2026-11-19', 'end_date' => '2026-11-19']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('days.data.0.provider_stale', false)
            ->where('days.data.0.matches_provider', true)
            ->where('totals.stale_days', 0)
            ->where('totals.matches_provider', true));
});

it('rejects an end date in the future', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', ['end_date' => '2026-11-20']))
        ->assertSessionHasErrors(['end_date' => 'The end date field must be a date before or equal to today.']);
});

it('paginates the per-user rows ordered by spend', function () {
    foreach (range(1, 12) as $index) {
        Generation::factory()
            ->for(User::factory()->create(['name' => "Customer {$index}"]))
            ->succeeded($index * 1_000_000)
            ->create(['completed_at' => '2026-11-19 08:00:00']);
    }

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', ['start_date' => '2026-11-19', 'end_date' => '2026-11-19', 'users_page' => 2]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 2)
            ->where('users.total', 12)
            ->where('users.current_page', 2)
            ->where('users.data.0.name', 'Customer 2')
            ->where('users.data.1.name', 'Customer 1'));
});

describe('sync', function () {
    it('queues a sync of the selected range for admins', function () {
        Queue::fake([SyncProviderUsage::class]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.report.sync'), ['start_date' => '2026-01-01', 'end_date' => '2026-11-19'])
            ->assertRedirect();

        Queue::assertPushed(SyncProviderUsage::class, fn (SyncProviderUsage $job) => $job->startDate === '2026-01-01'
            && $job->endDate === '2026-11-19');
    });

    it('forbids non-admin users with 403', function () {
        Queue::fake([SyncProviderUsage::class]);

        $this->actingAs(User::factory()->create())
            ->post(route('admin.report.sync'), ['start_date' => '2026-01-01', 'end_date' => '2026-11-19'])
            ->assertForbidden();

        Queue::assertNothingPushed();
    });
});
