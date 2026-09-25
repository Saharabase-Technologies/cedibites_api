<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use Illuminate\Notifications\Notification;

/**
 * One text to head office about something waiting on them.
 *
 * Not queued, for the same reason as the tech admin's error texts: the sender
 * decides when it goes (after the response, or from the scheduler), and a
 * stalled queue must not silence it.
 */
class AdminAlertNotification extends Notification
{
    public function __construct(
        public string $message,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [SmsChannel::class];
    }

    public function toSms(object $notifiable): string
    {
        return $this->message;
    }
}
