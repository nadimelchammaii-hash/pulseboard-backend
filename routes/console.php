<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// "Try the demo" accounts whose time is up, with everything in them. Runs in the
// `scheduler` container (php artisan schedule:work) in production.
Schedule::command('demo:prune')->hourly()->withoutOverlapping();
