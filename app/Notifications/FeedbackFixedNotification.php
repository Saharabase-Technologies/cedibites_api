<?php

namespace App\Notifications;

use App\Models\FeedbackReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Closes the loop (P5): tells a reporter their report was fixed. In-app only,
 * deep-linked to their my-reports page. Fired best-effort on the status
 * transition to `fixed`.
 */
class FeedbackFixedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public FeedbackReport $report) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $label = $this->report->number ? "#{$this->report->number}" : 'your report';

        return [
            'type' => 'feedback_fixed',
            'feedback_id' => $this->report->id,
            'feedback_number' => $this->report->number,
            'title' => 'Your report was fixed',
            'message' => "Thanks for flagging {$label} — it's been fixed.",
            'link' => '/my-feedback',
        ];
    }
}
