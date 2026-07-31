<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'brevo' => [
        'key' => env('BREVO_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
    ],

    /*
     * Plain-English explanations for errors the hand-written table in
     * ErrorExplainer does not recognise. Reuses the Groq key already set up for
     * feedback voice-note transcription — same account, same key, but a text
     * model rather than Whisper. Groq's API is OpenAI-compatible, so pointing
     * base_url at OpenAI and supplying an OpenAI key works unchanged.
     *
     * Leave the key unset to disable: known errors are still explained from the
     * table, and unknown ones fall back to the raw message.
     */
    'error_explainer' => [
        'key' => env('ERROR_EXPLAINER_KEY', env('GROQ_API_KEY')),
        'model' => env('ERROR_EXPLAINER_MODEL', 'llama-3.3-70b-versatile'),
        'base_url' => env('ERROR_EXPLAINER_BASE_URL', 'https://api.groq.com/openai/v1'),
    ],

    'sms' => [
        // NOTE: not currently read by anything. SmsChannel always calls
        // HubtelSmsService directly, so setting this to 'log' does NOT stop
        // real messages going out. Left in place pending a real driver switch.
        'driver' => env('SMS_DRIVER', 'log'), // log, africastalking, hubtel

        // Health alerting (see CheckSmsHealth).
        // Hours before the same ongoing incident is re-alerted. A changed cause
        // or an escalation to critical alerts immediately regardless.
        'alert_cooldown_hours' => env('SMS_ALERT_COOLDOWN_HOURS', 6),

        // Comma-separated fallback recipients, for when no user holds
        // view_system_health (which was the case in production until 2026-07-31).
        'alert_emails' => env('SMS_ALERT_EMAILS', ''),
    ],

    'africastalking' => [
        'username' => env('AT_USERNAME'),
        'api_key' => env('AT_API_KEY'),
        'sender_id' => env('AT_SENDER_ID', 'CediBites'),
    ],

    'hubtel' => [
        // SMS credentials
        'client_id' => env('HUBTEL_CLIENT_ID'),
        'client_secret' => env('HUBTEL_CLIENT_SECRET'),
        'sender_id' => env('HUBTEL_SENDER_ID', 'CediBites'),
        'sms_base_url' => env('HUBTEL_SMS_BASE_URL', 'https://sms.hubtel.com/v1/messages'),

        // Online Checkout credentials (payproxyapi - for customer checkout redirect)
        'payment_client_id' => env('HUBTEL_PAYMENT_CLIENT_ID', env('HUBTEL_CLIENT_ID')),
        'payment_client_secret' => env('HUBTEL_PAYMENT_CLIENT_SECRET', env('HUBTEL_CLIENT_SECRET')),
        'merchant_account_number' => env('HUBTEL_MERCHANT_ACCOUNT_NUMBER'),
        'base_url' => env('HUBTEL_BASE_URL', 'https://payproxyapi.hubtel.com'),
        'status_check_url' => env('HUBTEL_STATUS_CHECK_URL', 'https://api-txnstatus.hubtel.com'),

        // Direct Receive Money credentials (rmp.hubtel.com - for POS mobile money)
        'rmp_client_id' => env('HUBTEL_RMP_CLIENT_ID', env('HUBTEL_PAYMENT_CLIENT_ID', env('HUBTEL_CLIENT_ID'))),
        'rmp_client_secret' => env('HUBTEL_RMP_CLIENT_SECRET', env('HUBTEL_PAYMENT_CLIENT_SECRET', env('HUBTEL_CLIENT_SECRET'))),
        'rmp_base_url' => env('HUBTEL_RMP_BASE_URL', 'https://rmp.hubtel.com'),

        // Verification API (rnv.hubtel.com - MoMo registration & name query)
        'rnv_base_url' => env('HUBTEL_RNV_BASE_URL', 'https://rnv.hubtel.com'),

        // Callback IP allowlist — comma-separated list of Hubtel's callback IPs.
        // When set, any callback from an IP not in this list is rejected with 403.
        // Leave empty (unset) to allow all IPs (useful for local development).
        'allowed_ips' => env('HUBTEL_ALLOWED_IPS'),

        /*
         * IMPORTANT: Both the Status Check API and the RMP API require IP whitelisting.
         * Submit your server's public IP to your Retail Systems Engineer to be whitelisted.
         * Without IP whitelisting, requests will receive 403 Forbidden responses.
         *
         * ENV variables to set:
         *   HUBTEL_MERCHANT_ACCOUNT_NUMBER   - Your POS Sales ID (used in RMP endpoint URL)
         *   HUBTEL_PAYMENT_CLIENT_ID         - Online Checkout API client ID
         *   HUBTEL_PAYMENT_CLIENT_SECRET     - Online Checkout API client secret
         *   HUBTEL_RMP_CLIENT_ID             - RMP receive money client ID (or same as payment)
         *   HUBTEL_RMP_CLIENT_SECRET         - RMP receive money client secret (or same as payment)
         *   HUBTEL_ALLOWED_IPS               - Comma-separated list of Hubtel callback IPs for security
         */
    ],

];
