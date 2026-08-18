<?php

namespace App\Jobs;

use App\Helpers\PhoneHelper;
use App\Models\StaffMessageRecipient;
use App\Services\HubtelSmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The last rung of the delivery ladder: SMS, but only for a message still unread
 * when its window ran out.
 *
 * Carries a recipient ID rather than the model. A serialised model is a snapshot
 * of the row as it stood when the job was queued — and re-reading the row is the
 * entire purpose of this job, since the whole question is whether `read_at` has
 * been stamped in the meantime. The campaign work learned this one the hard way.
 */
class EscalateStaffMessageToSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function __construct(
        public int $recipientId,
    ) {}

    public function handle(HubtelSmsService $sms): void
    {
        $recipient = StaffMessageRecipient::with('message', 'user')->find($this->recipientId);

        if (! $recipient || ! $recipient->message) {
            return;
        }

        // Already read it. This is the branch that runs most of the time, and it
        // is the point of the whole job.
        if ($recipient->read_at !== null) {
            return;
        }

        // Already escalated — a retry, or a duplicate dispatch. Sending twice
        // costs money and reads as the system panicking.
        if ($recipient->sms_sent_at !== null) {
            return;
        }

        $message = $recipient->message;

        // The message expired while it sat unread. Texting somebody about a
        // notice that no longer applies is worse than not texting at all.
        if ($message->expires_at !== null && $message->expires_at->isPast()) {
            $recipient->forceFill(['sms_status' => 'skipped_expired'])->save();

            return;
        }

        $phone = $recipient->user?->phone;

        if (! $phone) {
            $recipient->forceFill(['sms_status' => 'no_phone'])->save();

            return;
        }

        try {
            // Hubtel wants 233… with no plus.
            $sms->sendSingle(
                PhoneHelper::toInternational($phone),
                $this->body($message->subject, $message->body),
                'staff_message',
            );

            $recipient->forceFill([
                'sms_sent_at' => now(),
                'sms_status' => 'sent',
            ])->save();
        } catch (\Throwable $e) {
            $recipient->forceFill(['sms_status' => 'failed'])->save();

            Log::warning('Staff message SMS escalation failed', [
                'recipient_id' => $recipient->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * One SMS segment is 160 characters and every segment is billed. The subject
     * carries the urgency, so it survives and the body is what gets trimmed.
     */
    private function body(?string $subject, string $body): string
    {
        $flat = trim(preg_replace('/\s+/', ' ', $body) ?? '');
        $text = $subject ? "{$subject}: {$flat}" : $flat;

        return mb_strlen($text) <= 155 ? $text : mb_substr($text, 0, 152).'...';
    }

    public function failed(\Throwable $e): void
    {
        StaffMessageRecipient::whereKey($this->recipientId)
            ->update(['sms_status' => 'failed']);
    }
}
