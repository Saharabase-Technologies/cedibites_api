<?php

namespace App\Jobs;

use App\Models\FeedbackReport;
use App\Services\Feedback\FeedbackService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Transcribe a report's voice note off the request cycle. A no-op when no
 * transcription provider is configured.
 */
class TranscribeFeedbackReport implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $reportId) {}

    public function handle(FeedbackService $service): void
    {
        $report = FeedbackReport::find($this->reportId);
        if ($report) {
            $service->transcribeReport($report);
        }
    }
}
