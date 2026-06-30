<?php

namespace App\Events\Inventory;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Lightweight PO change signal. Carries scalars only — listeners refetch the
 * full PO via the API. Broadcast to anyone who can view purchase orders.
 */
class PurchaseOrderBroadcastEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $purchaseOrderId,
        public string $reference,
        public string $status,
        public string $changeType,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('inventory.purchase-orders')];
    }

    public function broadcastAs(): string
    {
        return 'purchase-order.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->purchaseOrderId,
            'reference' => $this->reference,
            'status' => $this->status,
            'type' => $this->changeType,
        ];
    }
}
