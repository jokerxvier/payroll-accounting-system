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
