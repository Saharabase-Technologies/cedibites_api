<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Head office alert numbers
    |--------------------------------------------------------------------------
    |
    | Texted when a branch opens late or with problems, when head office opens
    | a branch without the checklist, and when someone asks to cancel an
    | order. Kept in the platform settings panel; this is only the starting
    | value, and it is deliberately empty rather than baked into the code.
    |
    */

    'admin_phones' => env('ADMIN_ALERT_PHONES', ''),

    /*
    |--------------------------------------------------------------------------
    | Error texts to the tech admin
    |--------------------------------------------------------------------------
    |
    | Every fault on the platform error feed, sent by SMS to whoever holds
    | `view_system_health`. The error page only helps somebody who is looking
    | at it, and nobody looks at an error page while service is running.
    |
    | On by default in production only. Beta has its own copy of the tech
    | admin's account and phone, so leaving it on everywhere would text the
    | same person about a staging server's mistakes.
    |
    | All three are also editable from the platform settings panel.
    |
    */

    'tech_errors' => [
        'enabled' => (bool) env('TECH_ERROR_TEXTS_ENABLED', env('APP_ENV') === 'production'),

        // A reminder about the same fault, sent only if it has happened again
        // since the last text. A fault that stopped is not chased.
        'repeat_hours' => (int) env('TECH_ERROR_TEXTS_REPEAT_HOURS', 3),

        // Failed staff sign-ins are gathered into one text at most this often:
        // who, how many times, and why.
        'sign_in_roundup_hours' => (int) env('TECH_ERROR_TEXTS_SIGN_IN_ROUNDUP_HOURS', 6),

        // A bad day must not become a bill. When the cap is reached one last
        // text says how many more are waiting, and the rest stay on the page.
        'daily_cap' => (int) env('TECH_ERROR_TEXTS_DAILY_CAP', 20),

        // Only occurrences this recent are news. Wider than the five-minute
        // schedule so a skipped run loses nothing, narrow enough that the
        // first run after a deploy does not text yesterday's errors.
        'lookback_minutes' => (int) env('TECH_ERROR_TEXTS_LOOKBACK_MINUTES', 30),

        // A job still waiting this long after it became due means no worker
        // is taking jobs. That is how the SMS instrumentation once shipped
        // and recorded nothing: the workers were running old code.
        'queue_stalled_minutes' => (int) env('TECH_ERROR_TEXTS_QUEUE_STALLED_MINUTES', 10),

        // Past this, uploads and logs start failing, and Postgres stops
        // writing altogether once the disk is full.
        'disk_full_percent' => (int) env('TECH_ERROR_TEXTS_DISK_FULL_PERCENT', 90),
    ],

];
