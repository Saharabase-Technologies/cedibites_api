<?php

namespace App\Events;

use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast immediately, not through the queue.
 *
 * As `ShouldBroadcast` this went onto the same queue as everything else an
 * order kicks off — the customer's confirmation SMS, a notification for every
 * active employee at the branch, the high-value manager alert — and it was
 * dispatched *after* all of them, so it waited behind the lot. The kitchen
 * reported receiving the SMS before the order appeared on the board, which is
 * exactly that ordering made audible.
 *
 * A broadcast is one publish to Reverb taking a few milliseconds, so there is
 * nothing here worth deferring; queueing it only ever added latency to the one
 * signal that has to be immediate. Notifications stay queued, where they belong.
 */
class OrderBroadcastEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Order $order,
        public string $changeType,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("orders.branch.{$this->order->branch_id}"),
            new Channel("orders.{$this->order->order_number}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->changeType,
            'order' => (new OrderResource(
                $this->order->load(['branch', 'items.menuItem.category', 'items.menuItemOption.media'])
            ))->toArray(request()),
        ];
    }
}
