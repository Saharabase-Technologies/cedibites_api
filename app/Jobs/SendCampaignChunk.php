<?php

namespace App\Jobs;

use App\Enums\CampaignRecipientState;
use App\Enums\SmsFailureReason;
use App\Exceptions\SmsBatchRefused;
use App\Services\Campaigns\CampaignLedger;
use App\Services\Campaigns\CampaignSender;
use App\Services\HubtelSmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One chunk of a campaign, sent and accounted for.
 *
 * The queue never retries this job, on purpose. A retried job cannot tell
 * whether its first run reached Hubtel, and guessing wrong texts five hundred
 * people twice. What happens after a chunk does not go is decided here instead,
 * from what Hubtel actually said:
 *
 *   Accepted. Recorded, and the campaign moves on.
 *
 *   Refused, for a reason the next chunk would meet too (no credit, bad
 *   credentials, anything unrecognised). Nothing was sent, so the chunk goes
 *   back in the queue untouched and the whole campaign pauses. Somebody fixes
 *   the cause and presses resume.
 *
 *   Refused for being sent too fast. Nothing was sent, so the same chunk is
 *   queued again a minute later, a limited number of times, then pauses.
 *
 *   Refused because of the numbers in it. Recorded as refused, and the campaign
 *   carries on with the other chunks.
 *
 *   No clear answer (a timeout, a dropped connection, a reply we cannot read).
 *   The message may have gone. The chunk is marked unsure, never resent on its
 *   own, and the campaign pauses.
 */
class SendCampaignChunk implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<int, string>  $recipients  Hubtel format — 233XXXXXXXXX
     * @param  int  $attempt  Which try this is, for a chunk queued again after a rate limit
     */
    public function __construct(
        public int $campaignId,
        public array $recipients,
        public string $message,
        public int $attempt = 1,
    ) {}

    public function handle(HubtelSmsService $sms, CampaignSender $sender, ?CampaignLedger $ledger = null): void
    {
        if ($this->recipients === []) {
            return;
        }

        $ledger ??= app(CampaignLedger::class);

        // A campaign sent before the ledger existed has no rows, and neither
        // does a chunk that was already on the queue when this shipped. Those
        // run as they always did: send, count, no pause.
        $tracked = $ledger->hasRows($this->campaignId);
        $recipients = $this->recipients;

        if ($tracked) {
            // Paused, or finished some other way. The rows stay queued for
            // whoever resumes it.
            if (! $sender->isSending($this->campaignId)) {
                return;
            }

            // Only the rows still queued. If another run of this chunk got
            // here first there is nothing left to claim and nothing is sent.
            $recipients = $ledger->claim($this->campaignId, $this->recipients);

            if ($recipients === []) {
                return;
            }
        }

        $count = count($recipients);

        try {
            $result = $sms->sendBatch(
                $recipients,
                $this->message,
                notification: 'campaign',
                // Keeps this out of the SMS health signal. A failed campaign
                // must not trip the alert that guards order notifications, and a
                // large successful one must not dilute the failure rate enough
                // to hide a real outage. See SmsDeliveryAttempt::scopeTransactional().
                isCampaign: true,
                campaignId: $this->campaignId,
            );
        } catch (\Throwable $e) {
            $this->notSent($e, $recipients, $tracked, $sender, $ledger);

            return;
        }

        $batchId = $result['batchId'] ?? null;

        $ledger->settle($this->campaignId, $recipients, CampaignRecipientState::Accepted, batchId: $batchId);

        $sender->recordChunkResult(
            $this->campaignId,
            sent: $count,
            failed: 0,
            cost: $this->costOf($result, $count),
            // Kept so the delivery poll can ask what actually arrived and
            // what it cost. This is the only handle Hubtel gives us on a
            // batch after the fact.
            batchId: $batchId,
        );
    }

    /**
     * The worker gave up on this job: it ran out of time, or was killed.
     *
     * handle() catches everything Hubtel can do, so this only runs when the
     * process itself died. Rows it had claimed are still marked as sending and
     * would otherwise stay that way, leaving the campaign in Sending for good.
     */
    public function failed(?\Throwable $exception): void
    {
        $abandoned = app(CampaignLedger::class)->abandon($this->campaignId, $this->recipients);

        if ($abandoned === 0) {
            return;
        }

        $sender = app(CampaignSender::class);
        $sender->recordChunkResult($this->campaignId, sent: 0, failed: $abandoned);
        $sender->pause($this->campaignId, SmsFailureReason::Unknown);
    }

    /**
     * Decide what a chunk that did not go means for the rest of the campaign.
     *
     * sendBatch has already written one attempt row per recipient, so the
     * detail is recorded. What is left is the ledger and the campaign's totals.
     *
     * @param  array<int, string>  $recipients
     */
    private function notSent(\Throwable $e, array $recipients, bool $tracked, CampaignSender $sender, CampaignLedger $ledger): void
    {
        $count = count($recipients);
        $reason = $e instanceof SmsBatchRefused
            ? $e->reason
            : SmsFailureReason::classify($e->getMessage());

        // A refusal, or a fault found before any request left (missing
        // credentials, a malformed number). Either way nothing was sent.
        $nothingSent = $e instanceof SmsBatchRefused
            || in_array($reason, [SmsFailureReason::ConfigMissing, SmsFailureReason::InvalidRecipient], true);

        if (! $tracked) {
            Log::error('Campaign chunk failed', [
                'campaign_id' => $this->campaignId,
                'recipients' => $count,
                'error' => $e->getMessage(),
            ]);

            $sender->recordChunkResult($this->campaignId, sent: 0, failed: $count);

            return;
        }

        if (! $nothingSent) {
            Log::error('Campaign chunk got no clear answer', [
                'campaign_id' => $this->campaignId,
                'recipients' => $count,
                'error' => $e->getMessage(),
            ]);

            $ledger->settle($this->campaignId, $recipients, CampaignRecipientState::Unsure, $reason);
            $sender->recordChunkResult($this->campaignId, sent: 0, failed: $count);
            $sender->pause($this->campaignId, $reason);

            return;
        }

        Log::warning('Campaign chunk refused', [
            'campaign_id' => $this->campaignId,
            'recipients' => $count,
            'reason' => $reason->value,
            'error' => $e->getMessage(),
        ]);

        // The numbers themselves. Waiting will not change them, and the other
        // chunks have nothing to do with it.
        if ($reason === SmsFailureReason::InvalidRecipient) {
            $ledger->settle($this->campaignId, $recipients, CampaignRecipientState::Refused, $reason);
            $sender->recordChunkResult($this->campaignId, sent: 0, failed: $count);

            return;
        }

        // Back in the queue, with the reason kept beside each row.
        $ledger->settle($this->campaignId, $recipients, CampaignRecipientState::Queued, $reason);

        if ($reason === SmsFailureReason::RateLimited && $this->attempt <= (int) config('campaigns.rate_limit_retries', 2)) {
            self::dispatch($this->campaignId, $recipients, $this->message, $this->attempt + 1)
                ->delay(now()->addSeconds(max(1, (int) config('campaigns.rate_limit_retry_seconds', 60))));

            return;
        }

        $sender->pause($this->campaignId, $reason);
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
