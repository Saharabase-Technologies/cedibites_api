<?php

namespace App\Http\Resources\StaffMessaging;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The recipient's view — their own copy, with the message inlined.
 *
 * Keyed by the RECIPIENT row id, not the message id. Every action a staff member
 * takes (read, acknowledge, reply) is against their own copy, and exposing the
 * message id as the handle would invite an endpoint that lets one person
 * acknowledge on everybody's behalf.
 *
 * Carries no delivery figures. Who else received this, and whether they have read
 * it, is the sender's business.
 *
 * @mixin \App\Models\StaffMessageRecipient
 */
class InboxMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $message = $this->message;

        return [
            'id' => $this->id,
            'message_id' => $message->id,
            'kind' => $message->kind->value,
            'kind_label' => $message->kind->label(),
            'interrupts' => $message->kind->interrupts(),
            'subject' => $message->subject,
            'body' => $message->body,
            'sender_name' => $message->sender?->name ?? 'CediBites IT',
            'is_automatic' => $message->sender_user_id === null,
            'sent_at' => $message->sent_at?->toIso8601String(),
            'expires_at' => $message->expires_at?->toIso8601String(),

            'requires_acknowledgement' => $message->requires_acknowledgement,
            'allow_custom_reply' => $message->allow_custom_reply,
            'quick_replies' => $message->quick_replies ?? [],

            'read_at' => $this->read_at?->toIso8601String(),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'quick_reply' => $this->quick_reply,
            'reply_body' => $this->reply_body,
            'replied_at' => $this->replied_at?->toIso8601String(),

            // A thread the staff member started, or replies to their query. Only
            // loaded on the detail view.
            'thread' => $this->when(
                $message->relationLoaded('replies'),
                fn () => $message->replies->map(fn ($reply) => [
                    'id' => $reply->id,
                    'body' => $reply->body,
                    'sender_name' => $reply->sender?->name ?? 'CediBites IT',
                    'is_automatic' => $reply->sender_user_id === null,
                    'sent_at' => $reply->sent_at?->toIso8601String(),
                ])->values(),
            ),
        ];
    }
}
