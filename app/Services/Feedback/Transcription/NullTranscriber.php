<?php

namespace App\Services\Feedback\Transcription;

/** No provider configured — admins play the audio. */
class NullTranscriber implements Transcriber
{
    public function transcribe(string $audio, string $filename): ?string
    {
        return null;
    }
}
