<?php

namespace App\Console\Commands;

use App\Services\Pixelforge\UsageSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pixelforge:sync-usage {--from= : First UTC day (Y-m-d); defaults to 2 days ago} {--to= : Last UTC day (Y-m-d); defaults to today}')]
#[Description('Store Pixelforge per-day usage (GET /v1/usage) for the admin report')]
class SyncProviderUsage extends Command
{
    public function handle(UsageSync $sync): int
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();

        // Today keeps changing as generations complete, and yesterday can still change for
        // generations finishing around midnight, so the default window covers both.
        $from = $this->option('from') ? CarbonImmutable::parse((string) $this->option('from'), 'UTC') : $today->subDays(2);
        $to = $this->option('to') ? CarbonImmutable::parse((string) $this->option('to'), 'UTC') : $today;

        if ($from->gt($to)) {
            $this->error('--from must be on or before --to.');

            return self::FAILURE;
        }

        $stored = $sync->sync($from, $to);

        $this->info("Stored Pixelforge usage for {$stored} day(s) from {$from->toDateString()} to {$to->toDateString()}.");

        return self::SUCCESS;
    }
}
