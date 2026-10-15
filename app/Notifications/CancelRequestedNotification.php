<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrderRound;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class CancelRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Order $order,
        public string $reason,
        public string $requestedByName,
        // Set when only one round of the order is to be cancelled (D45).
        public ?OrderRound $round = null,
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
        $orderNumber = $this->order->order_number;
        $customerName = $this->order->contact_name ?? 'Customer';

        $what = $this->round ? "round {$this->round->number} of {$customerName}'s order" : "{$customerName}'s order";

        return (new WebPushMessage)
            ->title($this->round ? "Cancel Request: round {$this->round->number} of #{$orderNumber}" : "Cancel Request — #{$orderNumber}")
            ->body("{$this->requestedByName} requested cancellation for {$what}: {$this->reason}")
            ->action('View Order', "view_order_{$this->order->id}")
            ->badge('/cblogo.webp')
            ->icon('/cblogo.webp')
            ->tag($this->round ? "cancel-request-{$this->order->id}-r{$this->round->number}" : "cancel-request-{$this->order->id}")
            ->data([
                'order_id' => $this->order->id,
                'order_number' => $orderNumber,
                'type' => 'cancel_requested',
                'url' => '/admin/orders',
            ]);
    }
}
