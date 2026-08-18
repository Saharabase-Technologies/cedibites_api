<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The domain a customer sees
    |--------------------------------------------------------------------------
    |
    | Deliberately the apex, not app.cedibites.com. Cloudflare already 301s the
    | apex to the app and preserves the path, so `cedibites.com/r/A7X9Kp` works
    | today with no DNS work — and it is four characters shorter, which is four
    | characters of message we are not paying for 28,000 times.
    |
    | Because only the token is stored, changing this value repoints every future
    | link while every link already sent keeps resolving.
    |
    */
    'base_url' => env('SHORT_LINK_BASE_URL', 'https://cedibites.com'),

    /*
    | Characters in a generated token. Base62, so six gives 5.7 x 10^10
    | combinations. There is nothing to guess *into* on a campaign link —
    | everybody in the blast receives the same URL — so the length is set by cost
    | rather than by secrecy. Per-order feedback links carry identity and use
    | their own, longer token; see config/order_feedback.php.
    */
    'token_length' => (int) env('SHORT_LINK_TOKEN_LENGTH', 6),

    /*
    | How long individual click rows are kept. The per-link total survives
    | pruning; only the timeline is trimmed.
    */
    'click_retention_days' => (int) env('SHORT_LINK_CLICK_RETENTION_DAYS', 180),

    /*
    | Hosts treated as ours. A target outside this list is flagged in the admin
    | list, because anyone who can create a link can point our branded domain at
    | a page wearing our name. Creation is permission-gated and activity-logged
    | for the same reason.
    */
    'own_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SHORT_LINK_OWN_HOSTS', 'cedibites.com,www.cedibites.com,app.cedibites.com,api.cedibites.com')),
    ))),

];
