<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The kill switch
    |--------------------------------------------------------------------------
    |
    | Off by default. Everything is built and the form works, but no order
    | triggers an SMS until this is turned on — because every completed order
    | becomes a message, and at current volumes that is real money spent
    | automatically rather than deliberately.
    |
    | The public form and the admin page stay live regardless. Turning this off
    | stops new requests going out; it does not hide feedback already collected.
    |
    */
    'enabled' => (bool) env('ORDER_FEEDBACK_ENABLED', false),

    /*
    | How long after an order completes to ask. Long enough that they have
    | actually eaten, short enough that they remember.
    */
    'delay_hours' => (int) env('ORDER_FEEDBACK_DELAY_HOURS', 3),

    /*
    | Never ask outside these hours. A 9pm dinner order plus three hours is
    | midnight, and the right gap after a late dinner is breakfast — so a request
    | landing outside the window rolls forward to the next morning rather than
    | being dropped.
    */
    'send_window' => [
        'start_hour' => (int) env('ORDER_FEEDBACK_WINDOW_START', 8),
        'end_hour' => (int) env('ORDER_FEEDBACK_WINDOW_END', 19),
    ],

    /*
    | One request per customer per day, however many times they order. Somebody
    | who buys lunch and dinner is a good customer, not somebody to text twice.
    */
    'per_customer_daily_cap' => (int) env('ORDER_FEEDBACK_DAILY_CAP', 1),

    /*
    | Links expire, so a message forwarded weeks later cannot seed feedback about
    | a meal nobody remembers.
    */
    'expires_after_days' => (int) env('ORDER_FEEDBACK_EXPIRY_DAYS', 7),

    /*
    | Base62 characters in the token. Eight, not the six a campaign link uses:
    | this one identifies a specific order, so guessing it in bulk would let
    | somebody walk order history and poison the feedback data. The route is
    | throttled on top.
    */
    'token_length' => (int) env('ORDER_FEEDBACK_TOKEN_LENGTH', 8),

];
