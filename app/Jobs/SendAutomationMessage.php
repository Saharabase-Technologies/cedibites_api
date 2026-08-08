<?php

namespace App\Jobs;

use App\Helpers\PhoneHelper;
use App\Models\AutomationFire;
use App\Services\Automation\AutomationGuard;
use App\Services\Automation\MessageRenderer;
use App\Services\HubtelSmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Send one automated message, hours after the thing that caused it.
 *
 * EVERY GUARD IS RE-CHECKED HERE, not just at evaluation. That is the whole
 * reason this is a job and not a send: three hours is a long time. By the time
 * it runs the order may have been cancelled, another rule may have messaged the
 * same person, the rule may have been switched off, or somebody may have pulled
 * the kill switch — and the one thing worse than an automated message is an
 * automated message somebody already tried to stop.
 *
 * Carries the fire id rather than the model. A serialised model is a snapshot of
 * a row as it was when the job was queued, and re-reading is the point.
 */
class SendAutomationMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Recorded when the order is no longer one we should be reacting to. */
    public const ORDER_CANCELLED = 'order_cancelled';

    public int $tries = 3;

    public function __construct(public readonly int $fireId) {}

    public function handle(
        AutomationGuard $guard,
        MessageRenderer $renderer,
        HubtelSmsService $sms,
    ): void {
        $fire = AutomationFire::with(['rule.shortLink', 'order'])->find($this->fireId);

        // Already sent, already stopped, or the rule was deleted underneath it.
        if (! $fire || $fire->sent_at || $fire->wasSuppressed() || ! $fire->rule) {
            return;
        }

        // The order was cancelled while this sat in the queue. Asking somebody
        // how their food was when it never arrived is the worst thing this
        // feature could do, and it is entirely possible without this check.
        if ($fire->order && $fire->order->status === 'cancelled') {
            $this->stop($fire, self::ORDER_CANCELLED);

            return;
        }

        // Excludes its own row: this firing is inside its own cooldown window by
        // definition, so without the exclusion nothing would ever send.
        $objection = $guard->objection($fire->rule, $fire->phone, excludeFireId: $fire->id);

        if ($objection !== null) {
            $this->stop($fire, $objection);

            return;
        }

        $message = $renderer->render($fire->rule, $fire->order);

        if (trim($message) === '') {
            $this->stop($fire, 'empty_message');

            return;
        }

        /*
         * `is_campaign` stays false, by the user's decision: an automated
         * message counts toward the SMS health verdict like any other
         * transactional send.
         *
         * The accepted consequence is that a badly written rule failing
         * repeatedly will drag that verdict and look like an outage. The trade
         * is worth it — nobody is watching for a text they did not send by
         * hand, so without this, automated messages could stop arriving and
         * nothing would notice.
         */
        $sms->sendSingle(
            PhoneHelper::toInternational($fire->phone),
            $message,
            'automation:'.$fire->rule->id,
        );

        $fire->update(['sent_at' => now()]);
    }

    /** Record why it did not go, so the log explains itself later. */
    private function stop(AutomationFire $fire, string $reason): void
    {
        $fire->update(['suppressed_reason' => $reason]);
    }

    /**
     * A send that failed every retry.
     *
     * Marked rather than left looking pending, so a fire with no sent_at and no
     * reason always means "still queued" and never "quietly gave up".
     */
    public function failed(\Throwable $e): void
    {
        Log::error('Automated message failed', ['fire_id' => $this->fireId, 'error' => $e->getMessage()]);

        AutomationFire::where('id', $this->fireId)
            ->whereNull('sent_at')
            ->whereNull('suppressed_reason')
            ->update(['suppressed_reason' => 'send_failed']);
    }
}
