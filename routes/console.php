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

// Every 5 min. A rule that notices a stalled order half an hour late has already
// let the thing it was watching for happen — the whole point is to reach somebody
// while the order can still be saved. The command is heavily guarded (cooldowns,
// a per-person hourly cap), so running it often does not mean messaging often.
Schedule::command('messages:evaluate-rules')->everyFiveMinutes()->withoutOverlapping();

// Every 15 min so a dead SMS pipe is caught the same hour, not the same month.
// The command is stateful about alerting — running it often does not mean
// mailing often. See CheckSmsHealth.
Schedule::command('sms:health-check')->everyFifteenMinutes()->withoutOverlapping();
