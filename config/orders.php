<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Preparation time estimate
    |--------------------------------------------------------------------------
    |
    | What the customer is told when an order is confirmed. Measured from the
    | branch's own recent history rather than guessed — see PrepTimeEstimator —
    | then clamped into this range.
    |
    | `max_minutes` is a service promise, not an observation: however slow the
    | kitchen has actually been, the confirmation SMS never quotes longer than
    | this. Raising it quotes longer waits to customers; lowering it quotes
    | times the kitchen may not hit. `default_minutes` is what a branch with no
    | history yet is quoted — a branch that has just opened, or one that has
    | never moved an order through `preparing`.
    |
    */

    'prep_time' => [
        'default_minutes' => (int) env('ORDER_PREP_DEFAULT_MINUTES', 12),
        'min_minutes' => (int) env('ORDER_PREP_MIN_MINUTES', 5),
        'max_minutes' => (int) env('ORDER_PREP_MAX_MINUTES', 15),

        /** How far back to look for completed orders when measuring. */
        'lookback_days' => (int) env('ORDER_PREP_LOOKBACK_DAYS', 30),

        /**
         * Most recent orders per branch to measure. Bounds the work done on
         * every order creation — without it a busy branch would pull a month of
         * status rows into memory each time somebody orders.
         */
        'sample_size' => (int) env('ORDER_PREP_SAMPLE_SIZE', 200),

        /**
         * Ignore anything outside this many minutes when measuring. A kitchen
         * that forgot to press "ready" until the next morning would otherwise
         * drag the figure up on its own.
         */
        'sane_max_minutes' => 180,

        /** Below this many measured orders, the branch is not judged on its own history. */
        'min_sample' => (int) env('ORDER_PREP_MIN_SAMPLE', 5),
    ],

];
