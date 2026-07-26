<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Purchase Order approval threshold (GHS)
    |--------------------------------------------------------------------------
    |
    | POs whose estimated total is at or above this amount require Admin
    | approval before they can move from draft → sent. Mirrors the frontend
    | PO_APPROVAL_THRESHOLD constant.
    |
    */
    'po_approval_threshold' => (float) env('IMS_PO_APPROVAL_THRESHOLD', 10000),

    /*
    |--------------------------------------------------------------------------
    | Default wastage approval threshold (GHS)
    |--------------------------------------------------------------------------
    */
    'wastage_threshold_default' => (float) env('IMS_WASTAGE_THRESHOLD', 500),

    /*
    |--------------------------------------------------------------------------
    | Business day cutoff (hour, 0-23, local time)
    |--------------------------------------------------------------------------
    |
    | Before this hour, the business day is still YESTERDAY's. A branch that
    | trades into the evening and counts up afterwards can run past midnight
    | and still be closing the day it actually worked, rather than being told
    | the date has changed underneath it.
    |
    | Ghana is UTC+0 all year and `app.timezone` is UTC, so this needs no
    | offset arithmetic. If the app is ever moved off UTC, revisit it.
    |
    | 03:00 is chosen to sit in genuinely dead hours. The cutoff must NOT be
    | near the start of the working day: a count completed while the morning's
    | first delivery is being unloaded would measure last night's shelf against
    | a ledger that already holds the new stock, and `count_adjustment` would
    | post the delivery away. DailyClosingService guards that case directly as
    | well, but the hour is the first line of defence.
    |
    */
    'business_day_cutoff_hour' => (int) env('IMS_BUSINESS_DAY_CUTOFF_HOUR', 3),

];
