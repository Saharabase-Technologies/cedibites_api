<?php

namespace App\Events;

use App\Models\Branch;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BranchAccessUpdatedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Branch $branch,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("orders.branch.{$this->branch->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'branch.access.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->branch->id,
            'extended_staff_access' => $this->branch->extended_staff_access,
            'extended_order_access' => $this->branch->extended_order_access,
            'staff_access_allowed' => $this->branch->isStaffAccessAllowed(),
            // Also fired when the branch is opened for the day, so every till
            // at it unlocks without anybody refreshing.
            'opening' => app(\App\Services\Openings\BranchOpeningService::class)->summary($this->branch),
        ];
    }
}
