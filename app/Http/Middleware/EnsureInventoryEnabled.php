<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInventoryEnabled
{
    /**
     * Block all IMS routes when the inventory feature flag is disabled.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('features.inventory.enabled', false)) {
            return response()->json([
                'message' => 'Inventory module is disabled.',
                'feature' => 'inventory',
                'enabled' => false,
            ], 404);
        }

        return $next($request);
    }
}
