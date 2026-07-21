<?php

namespace App\Services\Feedback\Transcription;

/**
 * Speech-to-text for voice notes, behind a swappable interface. The provider is
 * selected by config; when unconfigured it's a no-op (returns null → "admins
 * just play the audio"). Never throws into the caller.
 */
interface Transcriber
{
    /**
     * @param  string  $audio     raw audio bytes
     * @param  string  $filename  hint for the provider (extension matters)
     * @return string|null        transcript, or null when unavailable/failed
     */
    public function transcribe(string $audio, string $filename): ?string;
}
