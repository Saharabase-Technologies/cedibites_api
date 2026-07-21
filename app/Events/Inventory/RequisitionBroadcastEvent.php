<?php

namespace App\Events\Inventory;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Lightweight requisition change signal. Carries scalars only — listeners refetch
 * the full requisition via the API. Broadcast to anyone who can view the
 * inventory catalog (the requesting branch + the approving warehouse manager).
 */
class RequisitionBroadcastEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $requisitionId,
        public string $reference,
        public string $status,
        public string $changeType,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('inventory.requisitions')];
    }

    public function broadcastAs(): string
    {
        return 'requisition.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->requisitionId,
            'reference' => $this->reference,
            'status' => $this->status,
            'type' => $this->changeType,
        ];
    }
}
