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
    | THERE IS NO CAP BY DEFAULT. 0 means send to whoever the audience holds.
    |
    | It used to refuse anything over 2,000, on the reasoning that the console
    | was new and a mistyped segment must not become a real blast. The number was
    | picked before a single campaign had gone out, and it was picked to be
    | survivable rather than correct: 2,000 people is about GHS 48, the whole
    | 28,000 base is about GHS 680. Nothing about 2,000 describes an audience
    | anybody actually wants to reach.
    |
    | Reaching the whole customer base is the point of the console. A limit that
    | refuses the ordinary case, and is only movable by editing a server file, is
    | a limit that gets in the way every time and stops nothing on the day it
    | matters. What actually stands between a wrong click and 28,000 handsets is
    | seed mode, which is on, and the confirm screen, which spells out the count
    | and the total before it will do anything.
    |
    | The check is still here, exactly as it was, so a figure can be put back
    | with one env value if a mistake ever earns one. Set CAMPAIGN_RECIPIENT_CAP
    | to a number and it refuses again.
    |
    */
    'recipient_cap' => (int) env('CAMPAIGN_RECIPIENT_CAP', 0),

    /*
    |--------------------------------------------------------------------------
    | Chunking and throughput
    |--------------------------------------------------------------------------
    |
    | Hubtel's batch ceiling is widely reported as 5,000 and has never been
    | confirmed in primary documentation — their docs are gone (see
    | docs/SMS_CAMPAIGNS_PLAN.md). So the size here is chosen against our own
    | failure modes rather than against a number nobody can verify.
    |
    | 500 is the standing default, not a figure waiting to be raised.
    |
    | Three reasons, none of them about Hubtel's limit:
    |
    |   A rejected chunk costs 500 people. The job does not retry, on purpose
    |   (see SendCampaignChunk), so whatever a chunk holds is what a rejection
    |   quietly loses. There is no resend endpoint to recover it with.
    |
    |   Every recipient gets its own INSERT in HubtelSmsService::recordBatch,
    |   so the chunk size is also the number of round trips one job makes to
    |   Postgres. At 500 the job finishes well inside the worker timeout. At
    |   1,000, a slow Hubtel response plus a loaded database can approach it —
    |   and a job killed after the batch was accepted but before
    |   recordChunkResult runs leaves a campaign that sent the messages, kept no
    |   batch id, and can never reach isFinished(). It sits in `sending` for
    |   good.
    |
    |   The delivery poll re-reads every batch every fifteen minutes for two
    |   days. More chunks means more batch ids, which is the one thing smaller
    |   chunks cost. It is a GET per batch and it is cheap.
    |
    | The delay spaces chunks out so a campaign does not arrive as one spike.
    | 3,000 recipients is six chunks over twenty-five seconds.
    |
    */
    'chunk_size' => (int) env('CAMPAIGN_CHUNK_SIZE', 500),
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
