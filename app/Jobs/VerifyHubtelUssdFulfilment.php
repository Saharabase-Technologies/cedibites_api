<?php

namespace App\Jobs;

use App\Models\HubtelUssdFulfilment;
use App\Models\HubtelUssdSession;
use App\Services\Payments\UssdPayments;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Ask Hubtel whether the paid dial in one of its fulfilments is real.
 *
 * Queued for the same reason the payment notifications are: Hubtel is waiting
 * on our answer to its post, and the status check can take seconds. A payment
 * the status check cannot find yet is asked about again, five times over
 * about fifteen minutes, before it is given up.
 */
class VerifyHubtelUssdFulfilment implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Seconds before each retry. */
    private const DELAYS = [15, 45, 120, 600];

    public function __construct(public int $fulfilmentId) {}

    public function handle(UssdPayments $payments): void
    {
        $fulfilment = HubtelUssdFulfilment::find($this->fulfilmentId);

        if (! $fulfilment || $fulfilment->outcome !== null) {
            return;
        }

        $outcome = $payments->verify($fulfilment, $this->attempts() >= $this->tries);

        if ($outcome === UssdPayments::RETRY) {
            $this->release(self::DELAYS[$this->attempts() - 1] ?? 600);

            return;
        }

        $fulfilment->forceFill(['outcome' => $outcome, 'checked_at' => now()])->save();

        if (! in_array($outcome, ['paid', 'duplicate'], true)) {
            Log::warning('A USSD fulfilment did not reach the till', [
                'id' => $fulfilment->id,
                'outcome' => $outcome,
            ]);

            return;
        }

        $session = HubtelUssdSession::where('session_id', $fulfilment->session_id)->first();

        if ($session && $session->hubtel_order_id && ! $session->acked_at) {
            AcknowledgeHubtelUssdFulfilment::dispatch($session->id);
        }
    }
}
