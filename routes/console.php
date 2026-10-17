<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Owners get their end-of-day summary once their outlet has closed. Checked often so
// that, on hosting that sleeps, a missed day is caught up rather than skipped.
Schedule::command('floatbook:daily-summary')->everyFifteenMinutes()->withoutOverlapping();

// The demo business is rebuilt overnight, so visitors always see the same tidy books.
Schedule::command('floatbook:reset-demo')->everyFifteenMinutes()->withoutOverlapping();
