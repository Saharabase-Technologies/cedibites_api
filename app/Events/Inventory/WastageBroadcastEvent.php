<?php

namespace App\Events\Inventory;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Lightweight wastage change signal. Carries scalars only - listeners refetch.
 * Matters most for the warehouse manager: a branch declaring a loss above the
 * threshold should land in his queue while he is looking at the screen, not
 * whenever he next reloads.
 */
class WastageBroadcastEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $wastageId,
        public string $reference,
        public string $status,
        public string $changeType,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('inventory.wastages')];
    }

    public function broadcastAs(): string
    {
        return 'wastage.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->wastageId,
            'reference' => $this->reference,
            'status' => $this->status,
            'type' => $this->changeType,
        ];
    }
}
