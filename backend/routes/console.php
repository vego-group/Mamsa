<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Transition bookings to "completed" once the stay has ended (SRS 5.2).
// Requires the system cron to run `php artisan schedule:run` every minute.
Schedule::command('bookings:complete')->dailyAt('00:30')->withoutOverlapping();
