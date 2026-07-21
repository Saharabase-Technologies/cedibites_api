<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feedback System
    |--------------------------------------------------------------------------
    */

    // Request-log rows older than this are purged daily. The table is a rolling
    // diagnostic, never an audit trail.
    'request_log_retention_days' => (int) env('FEEDBACK_REQUEST_LOG_RETENTION_DAYS', 14),

    // Voice-note transcription. Provider is null (disabled → no-op) unless set;
    // when disabled, admins simply play the audio.
    'transcription' => [
        'provider' => env('FEEDBACK_TRANSCRIPTION_PROVIDER'), // e.g. "openai"
        'openai_key' => env('OPENAI_API_KEY'),
        'openai_model' => env('FEEDBACK_TRANSCRIPTION_MODEL', 'whisper-1'),
    ],

];
