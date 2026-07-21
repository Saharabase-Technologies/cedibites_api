<?php

namespace App\Services\Feedback\Transcription;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpenAI Whisper transcription. Whisper accepts webm/opus directly, so the
 * widget's recording format needs no server-side transcode (this is why Whisper
 * was chosen over Gemini — see the plan's C15). Any failure returns null; a
 * missing transcript never breaks the report.
 */
class OpenAiTranscriber implements Transcriber
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
    ) {}

    public function transcribe(string $audio, string $filename): ?string
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(60)
                ->attach('file', $audio, $filename)
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => $this->model,
                ]);

            if (! $response->successful()) {
                Log::warning('Whisper transcription non-2xx', ['status' => $response->status()]);

                return null;
            }

            $text = trim((string) $response->json('text'));

            return $text !== '' ? $text : null;
        } catch (Throwable $e) {
            Log::warning('Whisper transcription failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
