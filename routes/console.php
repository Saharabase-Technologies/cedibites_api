<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('otp:cleanup')->hourly();
Schedule::command('menu:compute-smart-categories')->everySixHours();
Schedule::command('feedback:purge-request-logs')->daily();
