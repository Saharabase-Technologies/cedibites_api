<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Opening the branch
    |--------------------------------------------------------------------------
    |
    | A branch that requires the opening checklist sells nothing, at the till
    | or online, until its manager has completed the checklist for the day, or
    | head office has opened it without one.
    |
    | Each branch is switched on separately (branches.requires_opening_checklist),
    | so a branch not switched on trades exactly as it did before. `enforced`
    | is the kill switch for all of them at once, and is in the platform
    | settings panel.
    |
    */

    'enforced' => (bool) env('OPENINGS_ENFORCED', true),

    // How early the manager may start. Two hours before a 10:00 opening is
    // 08:00, which is also when staff may sign in to prepare.
    'early_start_minutes' => (int) env('OPENINGS_EARLY_START_MINUTES', 120),

    // Not open this long after opening time is late, and head office is told.
    'late_after_minutes' => (int) env('OPENINGS_LATE_AFTER_MINUTES', 15),

    // One more text if the branch is still not open this long after the first.
    'late_reminder_minutes' => (int) env('OPENINGS_LATE_REMINDER_MINUTES', 60),

    // Problems admitted at opening must be fixed within this. One period for
    // the whole opening, however many problems there are.
    'grace_minutes' => (int) env('OPENINGS_GRACE_MINUTES', 60),

    // After the grace period, a reminder this often while problems remain,
    // until the branch closes for the day.
    'reminder_hours' => (int) env('OPENINGS_REMINDER_HOURS', 3),

    // A customer who orders online just after opening time, while the manager
    // is still going through the checklist, waits a few minutes for the order
    // to reach the till. Past this, the branch is late and online stops taking
    // orders for it, rather than taking money for food nobody is cooking.
    'online_wait_minutes' => (int) env('OPENINGS_ONLINE_WAIT_MINUTES', 30),

    // Throwing today's opening away so the morning can be run again. For
    // testing on beta. Never in production: an opening is the record of who
    // took responsibility for a day.
    'allow_reset' => (bool) env('OPENINGS_ALLOW_RESET', env('APP_ENV') !== 'production'),

];
