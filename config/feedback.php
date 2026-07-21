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
    // when disabled, admins simply play the audio. Both providers accept the
    // widget's webm/opus directly (no transcode).
    'transcription' => [
        'provider' => env('FEEDBACK_TRANSCRIPTION_PROVIDER'), // "groq" | "openai"

        // Groq — OpenAI-compatible Whisper, free tier.
        'groq_key' => env('GROQ_API_KEY'),
        'groq_model' => env('FEEDBACK_GROQ_MODEL', 'whisper-large-v3'),

        // OpenAI Whisper.
        'openai_key' => env('OPENAI_API_KEY'),
        'openai_model' => env('FEEDBACK_TRANSCRIPTION_MODEL', 'whisper-1'),
    ],

];
