<?php

namespace App\Http\Resources\StaffMessaging;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The sender's view of a message. Carries the delivery figures.
 *
 * @mixin \App\Models\StaffMessage
 */
class StaffMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'subject' => $this->subject,
            'body' => $this->body,
            'image_url' => $this->imageUrl(),
            'audience' => $this->audience,
            'requires_acknowledgement' => $this->requires_acknowledgement,
            'allow_custom_reply' => $this->allow_custom_reply,
            'quick_replies' => $this->quick_replies ?? [],
            'sms_fallback_after_minutes' => $this->sms_fallback_after_minutes,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'recipient_count' => $this->recipient_count,

            // Null sender means a rule sent it. The frontend shows "Automatic"
            // rather than inventing a name, so a caution is never mistaken for
            // one a person sat down and wrote.
            'sender' => $this->whenLoaded('sender', fn () => [
                'id' => $this->sender?->id,
                'name' => $this->sender?->name,
            ]),
            'rule_id' => $this->rule_id,
            'is_automatic' => $this->sender_user_id === null,

            'stats' => $this->when(
                $request->routeIs('*.show') || $this->relationLoaded('recipients'),
                fn () => $this->deliveryStats(),
            ),

            'recipients' => StaffMessageRecipientResource::collection($this->whenLoaded('recipients')),
        ];
    }
}
