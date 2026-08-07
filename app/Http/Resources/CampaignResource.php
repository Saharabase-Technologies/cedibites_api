<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Campaign
 */
class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'message' => $this->message,

            'segment' => $this->segment->value,
            'segment_label' => $this->segment->label(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_editable' => $this->status->isEditable(),

            'scheduled_for' => $this->scheduled_for?->toIso8601String(),

            'short_link' => $this->whenLoaded('shortLink', fn () => $this->shortLink ? [
                'id' => $this->shortLink->id,
                'label' => $this->shortLink->label,
                'sms_url' => $this->shortLink->smsUrl(),
                'click_count' => $this->shortLink->click_count,
            ] : null),

            // The permanent record. Never recomputed from sms_delivery_attempts,
            // which is pruned — see the campaigns migration.
            'recipient_count' => $this->recipient_count,
            'sent_count' => $this->sent_count,
            'failed_count' => $this->failed_count,

            'segments_per_message' => $this->segments_per_message,
            'estimated_cost' => (float) $this->estimated_cost,
            // Null until Hubtel tells us what it charged. Deliberately not zero:
            // unmeasured must not read as free.
            'actual_cost' => $this->actual_cost === null ? null : (float) $this->actual_cost,

            /*
             * Click-through, the number that turns "we sent 28,000 messages" — a
             * cost — into "3,400 people opened the menu", which is a business
             * case. Null when the campaign carried no link, because zero would
             * read as nobody clicked.
             */
            'click_through_rate' => $this->clickThroughRate(),

            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),

            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function clickThroughRate(): ?float
    {
        if (! $this->relationLoaded('shortLink') || ! $this->shortLink || $this->sent_count === 0) {
            return null;
        }

        return round($this->shortLink->click_count / $this->sent_count * 100, 1);
    }
}
