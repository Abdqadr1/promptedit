<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.pixelforge.base_url' => 'https://pixelforge.test/v1']);
    Http::preventStrayRequests();
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(['object' => 'list', 'data' => [], 'total' => []])]);
    $this->travelTo('2026-11-19 12:00:00');
});

it('syncs the last three UTC days by default', function () {
    $this->artisan('pixelforge:sync-usage')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['start_date'] === '2026-11-17' && $request['end_date'] === '2026-11-19');
});

it('backfills the given range', function () {
    $this->artisan('pixelforge:sync-usage', ['--from' => '2026-10-01', '--to' => '2026-10-31'])->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['start_date'] === '2026-10-01' && $request['end_date'] === '2026-10-31');
});

it('rejects a range that ends before it starts', function () {
    $this->artisan('pixelforge:sync-usage', ['--from' => '2026-10-31', '--to' => '2026-10-01'])->assertFailed();

    Http::assertNothingSent();
});
