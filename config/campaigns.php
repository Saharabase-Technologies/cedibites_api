<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Seed-list mode
    |--------------------------------------------------------------------------
    |
    | When on, every send goes to this fixed list of numbers regardless of the
    | segment the operator picked. This is how the mechanism gets proven for a
    | few cedis instead of four figures.
    |
    | USE THIS FOR THE DEMO. Sending to 28,000 real customers proves nothing the
    | seed list does not, and costs roughly a thousand times more to find out.
    |
    | The chosen segment is still resolved and still reported, so the operator
    | sees the real recipient count next to the handful actually messaged.
    |
    */
    'seed_mode' => (bool) env('CAMPAIGN_SEED_MODE', true),

    'seed_list' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CAMPAIGN_SEED_NUMBERS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Hard recipient cap
    |--------------------------------------------------------------------------
    |
    | Refuses to send to more than this many people in one campaign. The audience
    | is 28,000+ and this console is new; a mistyped segment must not be able to
    | become a real blast. Raise it deliberately, once a send has been done and
    | the numbers have been checked.
    |
    */
    'recipient_cap' => (int) env('CAMPAIGN_RECIPIENT_CAP', 2000),

    /*
    |--------------------------------------------------------------------------
    | Chunking and throughput
    |--------------------------------------------------------------------------
    |
    | Hubtel's batch ceiling is widely reported as 5,000 and has never been
    | confirmed in primary documentation — their docs are gone (see
    | docs/SMS_CAMPAIGNS_PLAN.md). 1,000 is a conservative default: a rejected
    | chunk costs a thousand recipients rather than five thousand, and the size
    | can be raised the moment a real send confirms the ceiling.
    |
    | The delay spaces chunks out so a campaign does not arrive as one spike.
    |
    */
    'chunk_size' => (int) env('CAMPAIGN_CHUNK_SIZE', 1000),
    'inter_batch_delay_seconds' => (int) env('CAMPAIGN_INTER_BATCH_DELAY', 5),

    /*
    |--------------------------------------------------------------------------
    | Send window
    |--------------------------------------------------------------------------
    |
    | No marketing before 8am, after 7pm, or on a Sunday. Cheap to enforce now,
    | and it leaves the compliance track — which is a separate team's work — less
    | to retrofit. Times are in the app timezone.
    |
    */
    'send_window' => [
        'enabled' => (bool) env('CAMPAIGN_SEND_WINDOW_ENABLED', true),
        'start_hour' => (int) env('CAMPAIGN_SEND_WINDOW_START', 8),
        'end_hour' => (int) env('CAMPAIGN_SEND_WINDOW_END', 19),
        // ISO-8601 day numbers. 7 is Sunday.
        'blocked_days' => [7],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cost
    |--------------------------------------------------------------------------
    |
    | GHS per billed segment, used for the projection shown before sending.
    |
    | 0.0243 is not a guess any more — it is the rate Hubtel actually charged on
    | a real send from this account, read back from
    | `GET /v1/messages/batch/{batchId}` on 2026-08-07. It replaces the 0.05
    | placeholder taken from the general Ghana market range, which was roughly
    | double and would have overstated every projection by 2x.
    |
    | Still only a projection: the rate may differ by network or by volume tier.
    | The figure on a sent campaign is the measured one — see
    | CampaignDeliveryPoller — and if the two keep disagreeing, this is the value
    | to correct.
    |
    */
    'estimated_rate_per_segment' => (float) env('CAMPAIGN_RATE_PER_SEGMENT', 0.0243),

    /*
    |--------------------------------------------------------------------------
    | Delivery polling
    |--------------------------------------------------------------------------
    |
    | How far back campaigns:poll-deliveries looks. Delivery statuses settle
    | within minutes to hours; two days is generous and keeps the job cheap.
    |
    */
    'delivery_poll_hours' => (int) env('CAMPAIGN_DELIVERY_POLL_HOURS', 48),

];
