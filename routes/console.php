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

// Weekly, not daily: the click timeline is only large after a campaign and the
// retention window is measured in months. Nothing reads it in a hurry.
Schedule::command('links:prune-clicks')->weekly();

// Every 15 min so a dead SMS pipe is caught the same hour, not the same month.
// The command is stateful about alerting — running it often does not mean
// mailing often. See CheckSmsHealth.
Schedule::command('sms:health-check')->everyFifteenMinutes()->withoutOverlapping();
