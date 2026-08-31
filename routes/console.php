<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Capture a Horizon metrics snapshot every 5 minutes. Without this the
// Horizon dashboard's metrics pane stays permanently blank.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Recurring invoices. The app's first scheduled *business* task — until this,
// nothing depended on `schedule:run` actually being wired up on the server.
//
// 19:00 UTC is 03:00 in Manila, the quiet hour intended. The schedule itself
// stays in UTC deliberately: adding ->timezone('Asia/Manila') here without
// making every date read timezone-aware would fire on the previous UTC date
// and bill the previous period. The command resolves "today" in Manila itself.
//
// The mutex is pinned to redis and given an explicit expiry. Left to itself,
// withoutOverlapping() defaults to 24 hours — one OOM kill would silently skip
// a whole day of billing — and the lock resolves the *default* cache store,
// which config/cache.php sets to `database`. There is no cache_locks table and
// MigrationSafetyTest forbids adding one, so a machine without CACHE_STORE set
// would fail nightly with a SQL error no test could catch.
Schedule::useCache('redis');

Schedule::command('invoices:generate-recurring')
    ->dailyAt('19:00')
    ->withoutOverlapping(120)
    ->onOneServer();
