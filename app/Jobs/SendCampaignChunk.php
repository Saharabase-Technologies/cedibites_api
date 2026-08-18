<?php

namespace App\Jobs;

use App\Services\Campaigns\CampaignSender;
use App\Services\HubtelSmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One chunk of a campaign, sent and accounted for.
 *
 * Deliberately does not retry. A batch that Hubtel rejected for want of credit
 * would be retried into the same rejection; a batch it accepted might be
 * *delivered* and merely reported badly, and retrying that texts a thousand
 * people twice. Neither is worth the reattempt, so a failed chunk is recorded as
 * failed and the operator decides.
 */
class SendCampaignChunk implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<int, string>  $recipients  Hubtel format — 233XXXXXXXXX
     */
    public function __construct(
        public int $campaignId,
        public array $recipients,
        public string $message,
    ) {}

    public function handle(HubtelSmsService $sms, CampaignSender $sender): void
    {
        $count = count($this->recipients);

        if ($count === 0) {
            return;
        }

        try {
            $result = $sms->sendBatch(
                $this->recipients,
                $this->message,
                notification: 'campaign',
                // Keeps this out of the SMS health signal. A failed campaign
                // must not trip the alert that guards order notifications, and a
                // large successful one must not dilute the failure rate enough
                // to hide a real outage. See SmsDeliveryAttempt::scopeTransactional().
                isCampaign: true,
                campaignId: $this->campaignId,
            );

            $sender->recordChunkResult(
                $this->campaignId,
                sent: $count,
                failed: 0,
                cost: $this->costOf($result, $count),
                // Kept so the delivery poll can ask what actually arrived and
                // what it cost. This is the only handle Hubtel gives us on a
                // batch after the fact.
                batchId: $result['batchId'] ?? null,
            );
        } catch (\Throwable $e) {
            // sendBatch has already written one attempt row per recipient, so
            // the detail is recorded. What is left is the permanent count on the
            // campaign, which nothing else will write if this is not caught.
            Log::error('Campaign chunk failed', [
                'campaign_id' => $this->campaignId,
                'recipients' => $count,
                'error' => $e->getMessage(),
            ]);

            $sender->recordChunkResult($this->campaignId, sent: 0, failed: $count);
        }
    }

    /**
     * What Hubtel actually charged for this chunk, or null if it did not say.
     *
     * Null rather than zero: an unknown cost must stay visibly unknown, because
     * a campaign reporting GHS 0.00 spend reads as free rather than as
     * unmeasured — and the actual figure is the one the higher-ups will ask for.
     */
    private function costOf(array $result, int $recipients): ?float
    {
        $rate = $result['rate'] ?? null;

        if (! is_numeric($rate)) {
            return null;
        }

        // `rate` is per message. `units` is the billed segments per message,
        // which Hubtel has already multiplied into the rate on the single-send
        // path — so the chunk total is rate x recipients.
        return round((float) $rate * $recipients, 4);
    }
}
