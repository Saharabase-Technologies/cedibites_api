<?php

namespace App\Events\Inventory;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Lightweight reconciliation-cycle change signal. Carries scalars only —
 * listeners refetch the full cycle via the API. Broadcast to anyone who can view
 * the inventory catalog.
 */
class ReconciliationBroadcastEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $cycleId,
        public string $status,
        public string $changeType,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('inventory.reconciliations')];
    }

    public function broadcastAs(): string
    {
        return 'reconciliation.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->cycleId,
            'status' => $this->status,
            'type' => $this->changeType,
        ];
    }
}
