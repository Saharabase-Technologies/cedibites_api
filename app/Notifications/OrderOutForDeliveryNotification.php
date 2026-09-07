<?php

namespace App\Notifications;

use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use App\Channels\SmsChannel;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderOutForDeliveryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public $timeout = 30;

    public function __construct(
        public Order $order
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database', SmsChannel::class];

        // Only for somebody who asked. `pushSubscriptions` is empty for every
        // customer who never tapped the button, and an empty list would make
        // the channel do work for nothing on every order.
        if (method_exists($notifiable, 'pushSubscriptions') && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        if ($notifiable->email) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Order #{$this->order->order_number} is Out for Delivery")
            ->view('emails.orders.out-for-delivery', ['order' => $this->order]);
    }

    public function toSms(object $notifiable): string
    {
        $eta = $this->order->estimated_delivery_time?->format('g:i A') ?? '15-30 mins';

        return "CediBites: Your order #{$this->order->order_number} is out for delivery! ETA: {$eta}"."\n\nTrack it: ".$this->order->trackingUrl();
    }

    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'status' => 'out_for_delivery',
            'estimated_delivery_time' => $this->order->estimated_delivery_time,
            'message' => "Your order #{$this->order->order_number} is out for delivery!",
        ];
    }

    public function toWebPush(mixed $notifiable, mixed $notification): WebPushMessage
    {
        $n = $this->order->order_number;

        return (new WebPushMessage)
            ->title("Order {$n} is on the way")
            ->body('A rider is bringing it to you.')
            ->badge('/cblogo.webp')
            ->icon('/cblogo.webp')
            ->tag("order-{$this->order->id}")
            ->data([
                'order_number' => $n,
                'url' => "/orders/{$n}?t=".$this->order->trackingToken(),
            ]);
    }
}
