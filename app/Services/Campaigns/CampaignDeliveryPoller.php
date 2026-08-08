<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\DeliveryOutcome;
use App\Models\Campaign;
use App\Models\CampaignDelivery;
use App\Services\Contacts\PhoneNormaliser;
use App\Services\HubtelSmsService;
use Illuminate\Support\Facades\Log;

/**
 * What a campaign actually cost, and how much of it actually arrived.
 *
 * Everything before this point is what Hubtel *accepted*. This is what Hubtel
 * says *happened* — and the two are different numbers. A campaign can be
 * accepted in full and delivered to two thirds of the list, and until this ran
 * nothing recorded the gap.
 *
 * It is also the only way to get a real price. The send response carries no
 * rate; `GET /v1/messages/batch/{batchId}` does. Before this, every campaign
 * showed a projection built from a configured guess.
 */
class CampaignDeliveryPoller
{
    /**
     * Hubtel counts these as arrived. Anything else is in flight or lost.
     *
     * Compared case-insensitively — the field is prose, not an enum, and is one
     * provider-side wording change away from silently matching nothing.
     */
    private const DELIVERED = ['delivered'];

    public function __construct(private readonly HubtelSmsService $sms) {}

    /**
     * Ask about every campaign that has sent recently and might still change.
     *
     * @return int How many campaigns were updated
     */
    public function pollRecent(int $withinHours = 48): int
    {
        $campaigns = Campaign::whereNotNull('batch_ids')
            ->whereIn('status', [
                CampaignStatus::Sending->value,
                CampaignStatus::Sent->value,
                CampaignStatus::Failed->value,
            ])
            ->where('started_at', '>=', now()->subHours($withinHours))
            ->get();

        $updated = 0;

        foreach ($campaigns as $campaign) {
            if ($this->poll($campaign)) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Read one campaign's batches and write back what they say.
     *
     * Refuses to write a partial answer. If any batch cannot be read, the whole
     * update is abandoned: summing the batches we could reach would report a
     * cost lower than the truth and a delivery count lower than reality, and
     * both would look like measurements rather than gaps.
     */
    public function poll(Campaign $campaign): bool
    {
        $batchIds = (array) ($campaign->batch_ids ?? []);

        if ($batchIds === []) {
            return false;
        }

        $cost = 0.0;
        $delivered = 0;
        $seen = 0;
        $rows = [];

        foreach ($batchIds as $batchId) {
            $messages = $this->sms->batchStatus((string) $batchId);

            if ($messages === null) {
                Log::info('Campaign delivery poll incomplete — leaving the previous figures alone', [
                    'campaign_id' => $campaign->id,
                    'batch_id' => $batchId,
                ]);

                return false;
            }

            foreach ($messages as $message) {
                $seen++;
                // Charged whether or not it landed, so it is summed regardless
                // of status. The bill is the bill.
                $cost += (float) ($message['rate'] ?? 0);

                $providerStatus = (string) ($message['status'] ?? '');

                if (in_array(strtolower($providerStatus), self::DELIVERED, true)) {
                    $delivered++;
                }

                $phone = $this->phoneFrom($message);

                if ($phone !== null) {
                    $rows[$phone] = [
                        'campaign_id' => $campaign->id,
                        'phone' => $phone,
                        'outcome' => DeliveryOutcome::fromProviderStatus($providerStatus)->value,
                        'provider_status' => mb_substr($providerStatus, 0, 64) ?: null,
                        'rate' => isset($message['rate']) ? (float) $message['rate'] : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        if ($seen === 0) {
            return false;
        }

        $this->writeDeliveries($rows);

        $campaign->update([
            'actual_cost' => round($cost, 4),
            'delivered_count' => $delivered,
            'delivery_checked_at' => now(),
        ]);

        return true;
    }

    /**
     * Store one row per recipient, upserting so repeated polls settle rather
     * than accumulate.
     *
     * Keyed on (campaign_id, phone). The batch endpoint returns the full list
     * every time it is asked, so without the unique key a campaign polled every
     * fifteen minutes for two days would end up with a hundred and ninety-two
     * copies of itself.
     *
     * @param  array<string, array>  $rows
     */
    private function writeDeliveries(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            CampaignDelivery::upsert(
                $chunk,
                ['campaign_id', 'phone'],
                ['outcome', 'provider_status', 'rate', 'updated_at'],
            );
        }
    }

    /**
     * The recipient on a status row, normalised to how we store numbers.
     *
     * Hubtel answers with `to` in 233XXXXXXXXX form; everything on our side is
     * +233XXXXXXXXX. Without this the delivery rows could never be matched
     * against a contact or a customer, which is most of what they are for.
     */
    private function phoneFrom(array $message): ?string
    {
        $raw = (string) ($message['to'] ?? $message['To'] ?? $message['recipient'] ?? '');

        return $raw === '' ? null : PhoneNormaliser::normalise($raw);
    }
}
