<?php

namespace App\Events;

use App\Models\StaffMessageRecipient;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A staff message arriving, on the recipient's own private channel.
 *
 * One event per recipient rather than one per message. A message to forty riders
 * is forty events, which looks wasteful until you notice the alternative: a
 * single broadcast on a shared channel would put one person's caution about
 * their own work on a channel their colleagues are listening to.
 *
 * The payload carries enough to render the bell and the interstitial without a
 * refetch, but not the delivery figures — those are the sender's business.
 */
class StaffMessageEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public StaffMessageRecipient $recipient,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("staff-messages.{$this->recipient->user_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'staff-message.received';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $message = $this->recipient->message;

        return [
            'id' => $this->recipient->id,
            'message_id' => $message->id,
            'kind' => $message->kind->value,
            'subject' => $message->subject,
            'body' => $message->body,
            'requires_acknowledgement' => $message->requires_acknowledgement,
            'allow_custom_reply' => $message->allow_custom_reply,
            'quick_replies' => $message->quick_replies ?? [],
            'sender_name' => $message->sender?->name ?? 'CediBites IT',
            'sent_at' => $message->sent_at?->toIso8601String(),
        ];
    }
}
