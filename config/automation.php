<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | OFF. Nothing an automation rule can do reaches a customer while this is
    | false — rules still evaluate and still write to automation_fires, so the
    | reporting and the dry run work with the switch down. That is deliberate:
    | the way to gain confidence in a rule is to watch it match real orders for
    | a fortnight without sending anything.
    |
    | Two switches, not one. This answers "is the feature live?"; `is_active` on
    | each rule answers "is this rule live?". Turning the feature on must not
    | turn on every rule anybody was drafting.
    |
    */
    'enabled' => (bool) env('AUTOMATION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Cooldown
    |--------------------------------------------------------------------------
    |
    | Never message the same number twice inside this many days, counted across
    | EVERY rule rather than per rule. Per-rule cooldowns are the trap: three
    | rules each politely waiting three days still produce three texts in one
    | afternoon.
    |
    | Three days by the user's decision, 2026-08-08. Somebody who orders twice a
    | week can therefore receive around ten automated texts a month — about GHS
    | 0.24, so the thing being spent here is patience rather than money. The dry
    | run reports the busiest recipient in the sample so that is visible before
    | anything is switched on.
    |
    | A rule may set its own longer cooldown; it may not set a shorter one.
    |
    */
    'cooldown_days' => (int) env('AUTOMATION_COOLDOWN_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Dry run
    |--------------------------------------------------------------------------
    |
    | How far back to replay history when showing what a rule WOULD have done.
    | Thirty days is enough to catch a rule that fires on every order, which is
    | the failure this exists to prevent.
    |
    */
    'dry_run_days' => (int) env('AUTOMATION_DRY_RUN_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Cost
    |--------------------------------------------------------------------------
    |
    | Read from the campaign config rather than duplicated. The rate Hubtel
    | charges does not depend on which part of our software asked for the send,
    | and two copies of it disagreed once already.
    |
    */
    'rate_per_segment' => (float) env('CAMPAIGN_RATE_PER_SEGMENT', 0.0243),

];
