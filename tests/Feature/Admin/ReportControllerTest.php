<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.pixelforge.base_url' => 'https://pixelforge.test/v1']);
    Http::preventStrayRequests();
});

it('forbids non-admin users with 403', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.report'))
        ->assertForbidden();
});

it('reports spend, provider cost and profit and checks them against Pixelforge usage', function () {
    $this->travelTo('2026-11-19 12:00:00');
    $customer = User::factory()->create(['name' => 'Customer']);
    Generation::factory()->for($customer)->succeeded(70_000_000)->create(['completed_at' => '2026-11-19 08:00:00']);
    Generation::factory()->for($customer)->succeeded(30_000_000)->create(['completed_at' => '2026-11-19 09:00:00']);
    Generation::factory()->for($customer)->failed()->create(['completed_at' => '2026-11-19 10:00:00']);
    Generation::factory()->for($customer)->succeeded(10_000_000)->create(['completed_at' => '2026-11-18 23:59:59']);

    Http::fake([
        'pixelforge.test/v1/usage*' => Http::response([
            'object' => 'list',
            'data' => [
                ['date' => '2026-11-18', 'generations' => 1, 'cost_usd' => '0.009000000'],
                ['date' => '2026-11-19', 'generations' => 2, 'cost_usd' => '0.100000000'],
            ],
            'total' => ['cost_usd' => '0.109000000'],
        ]),
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', ['start_date' => '2026-11-18', 'end_date' => '2026-11-19']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Report')
            ->where('totals.charged', '$0.1375')
            ->where('totals.provider_cost', '$0.110000000')
            ->where('totals.profit', '$0.0275')
            ->where('days.data.0.date', '2026-11-19')
            ->where('days.data.0.succeeded', 2)
            ->where('days.data.0.failed', 1)
            ->where('days.data.0.charged', '$0.1250')
            ->where('days.data.0.matches_provider', true)
            ->where('days.data.1.date', '2026-11-18')
            ->where('days.data.1.matches_provider', false)
            ->where('users.data.0.name', 'Customer')
            ->where('users.data.0.profit', '$0.0275'));
});

it('paginates the daily rows across the whole range while totals cover every day', function () {
    $this->travelTo('2026-11-19 12:00:00');
    Generation::factory()->succeeded(70_000_000)->create(['completed_at' => '2026-11-19 08:00:00']);
    Generation::factory()->succeeded(30_000_000)->create(['completed_at' => '2026-11-05 08:00:00']);
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(['object' => 'list', 'data' => [], 'total' => []])]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', ['start_date' => '2026-11-05', 'end_date' => '2026-11-19', 'days_page' => 2]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('days.data', 5)
            ->where('days.total', 15)
            ->where('days.current_page', 2)
            ->where('days.last_page', 2)
            ->where('days.data.0.date', '2026-11-09')
            ->where('days.data.4.date', '2026-11-05')
            ->where('days.data.4.charged', '$0.0375')
            ->where('totals.charged', '$0.1250'));
});

it('paginates the per-user rows ordered by spend', function () {
    $this->travelTo('2026-11-19 12:00:00');
    foreach (range(1, 12) as $index) {
        Generation::factory()
            ->for(User::factory()->create(['name' => "Customer {$index}"]))
            ->succeeded($index * 1_000_000)
            ->create(['completed_at' => '2026-11-19 08:00:00']);
    }
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(['object' => 'list', 'data' => [], 'total' => []])]);

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
