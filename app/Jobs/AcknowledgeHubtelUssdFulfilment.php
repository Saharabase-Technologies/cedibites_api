<?php

namespace App\Jobs;

use App\Models\HubtelUssdSession;
use App\Services\Payments\UssdPayments;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Tell Hubtel a paid dial has reached the till.
 *
 * Hubtel wants this within an hour of its fulfilment. Six tries over about
 * twenty five minutes leave room for a slow afternoon at Hubtel's end. What
 * Hubtel last said is on the session's `ack_note` either way.
 */
class AcknowledgeHubtelUssdFulfilment implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** Seconds before each retry. */
    private const DELAYS = [30, 60, 120, 300, 900];

    public function __construct(public int $sessionId) {}

    public function handle(UssdPayments $payments): void
    {
        $session = HubtelUssdSession::find($this->sessionId);

        if (! $session || $session->acked_at || ! $session->hubtel_order_id) {
            return;
        }

        if ($payments->acknowledge($session)) {
            return;
        }

        if ($this->attempts() < $this->tries) {
            $this->release(self::DELAYS[$this->attempts() - 1] ?? 900);

            return;
        }

        Log::error('Hubtel did not take our USSD fulfilment callback', [
            'session' => $session->id,
            'said' => $session->ack_note,
        ]);
    }
}
