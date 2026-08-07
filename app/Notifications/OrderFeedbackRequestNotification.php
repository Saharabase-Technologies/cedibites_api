<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Models\OrderFeedback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * "How was it?", a few hours after the meal.
 *
 * The message text lives in a static method because most orders are guests, who
 * have no user record to notify — RequestOrderFeedback sends those straight
 * through HubtelSmsService. One method, so the two paths cannot drift into
 * saying different things.
 *
 * Deliberately no mail channel. This is a two-tap question on a phone; an email
 * asking the same thing is a second interruption for the same answer.
 */
class OrderFeedbackRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public $timeout = 30;

    public function __construct(public OrderFeedback $feedback) {}

    public function via(object $notifiable): array
    {
        return ['database', SmsChannel::class];
    }

    public function toSms(object $notifiable): string
    {
        return self::message($this->feedback);
    }

    /**
     * The one place this message is written.
     *
     * Kept short on purpose: at 160 characters it is one billed segment, and the
     * link is already as short as we can make it. No `https://` — handsets
     * auto-link a bare domain and the scheme costs eight characters for nothing.
     */
    public static function message(OrderFeedback $feedback): string
    {
        return 'CediBites: How was your order? Tell us in 10 seconds: '.$feedback->smsUrl();
    }

    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->feedback->order_id,
            'order_number' => $this->feedback->order?->order_number,
            'feedback_token' => $this->feedback->token,
            'message' => 'How was your order? Tap to tell us.',
        ];
    }
}
