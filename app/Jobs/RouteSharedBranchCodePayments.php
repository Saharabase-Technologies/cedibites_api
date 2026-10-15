<?php

namespace App\Jobs;

use App\Services\Payments\BranchCodeRouter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * An order was just rung. Try the routing rules again on the branch code
 * payments still on the shared list, since that order may be the one a
 * payment was for. Queued, so the sale never waits on it.
 */
class RouteSharedBranchCodePayments implements ShouldQueue
{
    use Queueable;

    public function handle(BranchCodeRouter $router): void
    {
        $router->routeShared();
    }
}
