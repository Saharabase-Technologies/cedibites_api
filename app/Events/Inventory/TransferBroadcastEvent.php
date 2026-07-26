<?php

namespace App\Events\Inventory;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Lightweight stock-transfer change signal. Carries scalars only - listeners
 * refetch the full transfer via the API. Broadcast to anyone who can view the
 * inventory catalog (including the receiving branch).
 */
class TransferBroadcastEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $transferId,
        public string $reference,
        public string $status,
        public string $changeType,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('inventory.transfers')];
    }

    public function broadcastAs(): string
    {
        return 'transfer.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->transferId,
            'reference' => $this->reference,
            'status' => $this->status,
            'type' => $this->changeType,
        ];
    }
}
