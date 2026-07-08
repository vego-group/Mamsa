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

// Purge abandoned passwordless sign-ups (User::prunable — blank profile,
// >24h old, no bookings). Rides the same system cron as above.
Schedule::command('model:prune')->dailyAt('01:00')->withoutOverlapping();

// Anti double-booking: mirror external platform calendars (Booking, Airbnb…)
// into unit blocked dates. 15 min = the industry-standard iCal sync window.
Schedule::command('calendar:sync')->everyFifteenMinutes()->withoutOverlapping();

// Release dates held by abandoned checkouts: unpaid pending bookings expire
// after 60 min (the frontend reuses a pending booking within that window).
Schedule::command('bookings:expire-pending')->everyFifteenMinutes()->withoutOverlapping();
