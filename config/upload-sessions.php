<?php

use App\Services\Uploads\Handlers\WastageEvidenceHandler;

return [

    /*
    |--------------------------------------------------------------------------
    | Purpose handlers
    |--------------------------------------------------------------------------
    |
    | The upload-session machinery knows about tokens, expiry and rate limits,
    | and nothing about any document. `purpose` is the join: each key here is a
    | value that may be stored on an `upload_sessions` row, and its handler is
    | the only place that knows what that document is or how to attach to it.
    |
    | Deliveries and daily counts have the same phone-as-camera problem. Adding
    | them means writing one handler and adding one line here.
    |
    | A purpose with no entry is a deployment mistake, not user input - the
    | service logs it and refuses.
    |
    */

    'handlers' => [
        'wastage_evidence' => WastageEvidenceHandler::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | The token is a bearer credential inside a screenshot-able square, so its
    | life is measured in minutes: long enough to walk from the laptop to the
    | store room and photograph a crate, short enough that a screen someone
    | glanced at yesterday is worthless.
    |
    | `max_video_kb` is the number to watch. Phone video runs roughly 15-30 MB
    | per 15 seconds at 1080p, and THREE separate limits will reject a big
    | upload at their defaults - PHP `upload_max_filesize`, PHP `post_max_size`
    | and nginx `client_max_body_size`. Raising this value without raising all
    | three on the server produces a failure that looks exactly like a code bug.
    |
    */

    'ttl_minutes' => (int) env('UPLOAD_SESSION_TTL_MINUTES', 10),
    'max_files' => (int) env('UPLOAD_SESSION_MAX_FILES', 10),

    'max_image_kb' => (int) env('UPLOAD_SESSION_MAX_IMAGE_KB', 10240),  // 10 MB
    'max_video_kb' => (int) env('UPLOAD_SESSION_MAX_VIDEO_KB', 51200),  // 50 MB

    /*
    | Accepted media. Videos are listed by the container each platform actually
    | produces: iPhone records .mov (quicktime), Android Chrome webm, older or
    | low-end Android 3gp. There is NO transcoding - an iPhone .mov may not play
    | in every desktop browser, and that is an accepted trade against requiring
    | ffmpeg on the VPS.
    */

    'image_mimetypes' => [
        'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif',
    ],

    'video_mimetypes' => [
        'video/mp4', 'video/quicktime', 'video/webm', 'video/3gpp',
    ],

];
