<?php

namespace App\Services\Feedback\Transcription;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Groq Whisper transcription. Groq's audio endpoint is OpenAI-compatible and
 * accepts the widget's webm/opus directly — no transcode, no ffmpeg — and runs
 * on a genuinely free tier. Any failure returns null; a missing transcript never
 * breaks the report.
 */
class GroqTranscriber implements Transcriber
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
                ->post('https://api.groq.com/openai/v1/audio/transcriptions', [
                    'model' => $this->model,
                    'response_format' => 'json',
                ]);

            if (! $response->successful()) {
                Log::warning('Groq transcription non-2xx', ['status' => $response->status()]);

                return null;
            }

            $text = trim((string) $response->json('text'));

            return $text !== '' ? $text : null;
        } catch (Throwable $e) {
            Log::warning('Groq transcription failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
