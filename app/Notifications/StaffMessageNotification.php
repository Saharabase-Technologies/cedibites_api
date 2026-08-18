<?php

namespace App\Notifications;

use App\Models\StaffMessageRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The push half of delivery — reaches a phone with the app closed.
 *
 * Web push only. There is no `database` channel here on purpose: the message
 * already has its own recipient row, and writing a second copy into Laravel's
 * `notifications` table would put the same message in two inboxes that then
 * disagree about whether it has been read.
 */
class StaffMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = [30, 120, 300];

    public function __construct(
        public StaffMessageRecipient $recipient,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(mixed $notifiable, mixed $notification): WebPushMessage
    {
        $message = $this->recipient->message;
        $sender = $message->sender?->name ?? 'CediBites IT';

        return (new WebPushMessage)
            ->title($message->subject ?: "Message from {$sender}")
            ->body($this->preview($message->body))
            ->badge('/cblogo.webp')
            ->icon('/cblogo.webp')
            // Tagged per recipient row, so a re-push replaces the old banner
            // instead of stacking a second copy of the same message.
            ->tag("staff-message-{$this->recipient->id}")
            ->data([
                'type' => 'staff_message',
                'recipient_id' => $this->recipient->id,
                'message_id' => $message->id,
                'kind' => $message->kind->value,
                'url' => '/staff/messages/'.$this->recipient->id,
            ]);
    }

    /**
     * Push banners are truncated by the OS at a length we do not control, and a
     * sentence cut mid-word reads as a broken app rather than a long message.
     */
    private function preview(string $body): string
    {
        $flat = trim(preg_replace('/\s+/', ' ', $body) ?? '');

        return mb_strlen($flat) <= 120 ? $flat : mb_substr($flat, 0, 117).'…';
    }
}
