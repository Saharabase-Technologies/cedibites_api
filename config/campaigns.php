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
    | CAMPAIGNS GO OUT AT ANY HOUR, ON ANY DAY, BY DEFAULT.
    |
    | Both restrictions started as guesses about what is polite, and both were
    | wrong for this business. Sunday — blocked on the assumption that weekend
    | marketing is intrusive — is the busiest sales day of the week, so the guard
    | was refusing to send on the day a campaign earns the most. The 8am–7pm
    | window was the same kind of guess.
    |
    | When to reach customers is a business decision. Refusing it in config, as a
    | validation error nobody thinks to question, is not where that decision
    | belongs.
    |
    | The machinery is still here and still enforced — it is the DEFAULTS that
    | are open. Note the window stays `enabled` rather than being switched off,
    | so that setting a blocked day alone actually takes effect; disabling the
    | whole guard by default would make CAMPAIGN_SEND_WINDOW_BLOCKED_DAYS a
    | setting that silently does nothing.
    |
    |   CAMPAIGN_SEND_WINDOW_START / _END  hours, app timezone (0 and 24 = any)
    |   CAMPAIGN_SEND_WINDOW_BLOCKED_DAYS  ISO-8601 days, e.g. "7" for Sunday
    |
    */
    'send_window' => [
        'enabled' => (bool) env('CAMPAIGN_SEND_WINDOW_ENABLED', true),
        'start_hour' => (int) env('CAMPAIGN_SEND_WINDOW_START', 0),
        'end_hour' => (int) env('CAMPAIGN_SEND_WINDOW_END', 24),
        'blocked_days' => array_values(array_filter(array_map(
            'intval',
            array_filter(array_map('trim', explode(',', (string) env('CAMPAIGN_SEND_WINDOW_BLOCKED_DAYS', '')))),
        ))),
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
