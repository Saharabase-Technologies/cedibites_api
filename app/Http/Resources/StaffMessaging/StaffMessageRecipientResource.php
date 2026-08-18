<?php

namespace App\Http\Resources\StaffMessaging;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One person's receipt, for the sender's screen.
 *
 * @mixin \App\Models\StaffMessageRecipient
 */
class StaffMessageRecipientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'role' => $this->user?->getRoleNames()->first(),
            ],
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch?->id,
                'name' => $this->branch?->name,
            ]),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'quick_reply' => $this->quick_reply,
            'reply_body' => $this->reply_body,
            'replied_at' => $this->replied_at?->toIso8601String(),
            'sms_sent_at' => $this->sms_sent_at?->toIso8601String(),
            'sms_status' => $this->sms_status,
        ];
    }
}
