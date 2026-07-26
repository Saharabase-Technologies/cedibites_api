<?php

namespace App\Events\Inventory;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A stock balance moved.
 *
 * Fired from the movement posting engine - the single choke point every stock
 * change flows through - so one signal covers purchases, transfers, production,
 * recipe deduction on a sale, reconciliation adjustments and wastage alike.
 * Screens that read balances rather than documents (items, dashboard, reports,
 * daily closing) have no document event to listen to; this is theirs.
 *
 * Scalars only - listeners refetch through the API, which re-applies the
 * caller's own location scope. Nothing here reveals a balance to someone who
 * could not already read it.
 */
class StockBroadcastEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $itemId,
        public int $locationId,
        public string $movementType,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('inventory.stock')];
    }

    public function broadcastAs(): string
    {
        return 'stock.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'movement_type' => $this->movementType,
        ];
    }
}
