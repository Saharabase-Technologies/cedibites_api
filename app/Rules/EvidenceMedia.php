<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * A photo or a short video offered as evidence.
 *
 * One rule rather than a `mimetypes:` + `max:` pair because photos and video
 * need different size caps and Laravel's declarative rules cannot branch on what
 * was actually uploaded. Shared by the desktop endpoint and the phone one, so a
 * file the laptop accepts is never rejected by the phone or the reverse.
 *
 * Validates by SNIFFED mime type, not the filename. A phone that names a video
 * `.jpg` still gets measured against the video cap, and an executable renamed
 * `.png` fails outright.
 */
class EvidenceMedia implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('That file did not arrive in one piece. Try again.');

            return;
        }

        $images = (array) config('upload-sessions.image_mimetypes', []);
        $videos = (array) config('upload-sessions.video_mimetypes', []);

        // getMimeType() sniffs the file's contents; getClientMimeType() is
        // whatever the phone claimed and is not trustworthy.
        $mime = strtolower((string) $value->getMimeType());

        $isImage = in_array($mime, $images, true);
        $isVideo = in_array($mime, $videos, true);

        if (! $isImage && ! $isVideo) {
            $fail('That is not a photo or a video. Use the camera, or pick a picture or clip already on the phone.');

            return;
        }

        $maxKb = (int) config($isVideo ? 'upload-sessions.max_video_kb' : 'upload-sessions.max_image_kb');
        $sizeKb = $value->getSize() / 1024;

        if ($sizeKb > $maxKb) {
            $maxMb = round($maxKb / 1024);

            $fail($isVideo
                ? "That clip is too big (limit {$maxMb} MB). Record about 15 seconds rather than a long video."
                : "That photo is too big (limit {$maxMb} MB). Take it again at a smaller size."
            );
        }
    }
}
