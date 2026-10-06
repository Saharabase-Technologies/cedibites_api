<?php

namespace App\Jobs;

use App\Models\HubtelPaymentNotification;
use App\Services\Payments\BranchCodePayments;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Ask Hubtel whether the payment in one of its posts is real.
 *
 * Queued, because Hubtel is waiting on our answer to its post and the status
 * check can take seconds. A payment the status check cannot find yet is asked
 * about again, five times over about fifteen minutes, before it is given up.
 */
class VerifyHubtelPaymentNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Seconds before each retry. */
    private const DELAYS = [15, 45, 120, 600];

    public function __construct(public int $notificationId) {}

    public function handle(BranchCodePayments $payments): void
    {
        $notification = HubtelPaymentNotification::find($this->notificationId);

        if (! $notification || $notification->outcome !== null) {
            return;
        }

        $lastAttempt = $this->attempts() >= $this->tries;
        $outcome = $payments->verify($notification, $lastAttempt);

        if ($outcome === BranchCodePayments::RETRY) {
            $this->release(self::DELAYS[$this->attempts() - 1] ?? 600);

            return;
        }

        $notification->forceFill(['outcome' => $outcome, 'checked_at' => now()])->save();

        if (in_array($outcome, ['not_found', 'not_paid'], true)) {
            Log::warning('Hubtel payment notification did not reach the till', [
                'id' => $notification->id,
                'account_number' => $notification->account_number,
                'outcome' => $outcome,
            ]);
        }
    }
}
