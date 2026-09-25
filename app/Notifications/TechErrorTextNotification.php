<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use Illuminate\Notifications\Notification;

/**
 * One text to the tech admin about one fault.
 *
 * Deliberately NOT ShouldQueue. Two of the faults this reports are "the queue
 * has stopped" and "a job keeps failing", and a text waiting in that same queue
 * would never leave it. The scheduler sends it directly instead.
 *
 * SMS only. The error page already holds the detail, and the point of this is
 * the one channel that reaches a phone in a pocket.
 */
class TechErrorTextNotification extends Notification
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
