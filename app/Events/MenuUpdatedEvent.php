<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The menu just changed: new prices went live, or were put back.
 *
 * Public, because a customer's browser needs it as much as a till. It carries
 * no menu, only the fact. Every listener refetches through the API, so it can
 * never hand anybody something the API would not.
 *
 * Without it a till keeps the menu it loaded. The cashier sees 90 in the basket
 * while the server charges 100, and the receipt says 100.
 */
class MenuUpdatedEvent implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public int $publicationId,
        public string $kind,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('menu')];
    }

    public function broadcastAs(): string
    {
        return 'menu.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'publication_id' => $this->publicationId,
            // published or undone
            'kind' => $this->kind,
        ];
    }
}
