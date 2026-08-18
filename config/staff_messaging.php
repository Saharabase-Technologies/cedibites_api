<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automation kill switch
    |--------------------------------------------------------------------------
    |
    | Off by default, and separate from each rule's own `is_active`. They answer
    | different questions — "is the feature live?" versus "is this rule live?" —
    | and switching the feature on must not switch on every draft somebody left
    | half-written.
    |
    | With this off the evaluator STILL RUNS and still records every fire it
    | would have made, marked `feature_off`. That is how a rule earns trust
    | before anyone lets it message a person.
    |
    */
    'automation_enabled' => env('STAFF_MESSAGING_AUTOMATION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Per-recipient hourly ceiling
    |--------------------------------------------------------------------------
    |
    | Across ALL rules, not per rule. Per-rule limits are the trap: four rules
    | each correctly restraining themselves still produce four messages in one
    | afternoon, each defensible on its own, and the person on the receiving end
    | experiences it as being shouted at by the software.
    |
    */
    'recipient_hourly_cap' => env('STAFF_MESSAGING_RECIPIENT_HOURLY_CAP', 3),

    /*
    |--------------------------------------------------------------------------
    | How far back an evaluation run looks
    |--------------------------------------------------------------------------
    |
    | Bounds every detector query. Without it the first run after the feature is
    | switched on would sweep the entire order history and message people about
    | orders from months ago.
    |
    */
    'lookback_hours' => env('STAFF_MESSAGING_LOOKBACK_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Default SMS escalation
    |--------------------------------------------------------------------------
    |
    | Minutes a message may sit unread before it is also sent as an SMS. Null
    | means never. Per-message and per-rule settings override this; it is only
    | the value the compose form starts on.
    |
    */
    'default_sms_fallback_minutes' => env('STAFF_MESSAGING_SMS_FALLBACK_MINUTES', 30),

];
