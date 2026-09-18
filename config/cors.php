<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'v1/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'https://cedibites.yarmy.tech',
        'https://app.cedibites.com',
        'https://beta.cedibites.com',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // `Date` has to be listed, or the browser hides it. The frontend learns
    // the server's clock from this header (lib/utils/serverClock.ts) and
    // stamps receipts with it, and `Date` is not one of the headers a browser
    // lets a cross-origin page read by default. With this empty, every till
    // printed its own clock: a reprint at Ashaiman on 2026-09-18 read 05:03 pm
    // for a print the server logged at 18:04, an hour and a minute behind.
    'exposed_headers' => ['Date'],

    'max_age' => 0,

    'supports_credentials' => true,

];
