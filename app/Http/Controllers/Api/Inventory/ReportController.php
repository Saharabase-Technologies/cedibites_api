<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Near-expiry / expired batch report. Lists open batches (remaining_qty > 0)
     * ordered soonest-expiry first, optionally limited to those expiring within
     * N days (default 14). Dateless batches are excluded.
     */
    public function expiringBatches(Request $request): JsonResponse
    {
        $within = (int) $request->integer('within_days', 14);
        $cutoff = now()->addDays($within)->toDateString();

        $batches = Batch::query()
            ->with(['item:id,sku,name,base_unit_id', 'item.baseUnit:id,symbol', 'location:id,name'])
            ->where('remaining_qty', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $cutoff)
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->orderBy('expiry_date')
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'expiry_date' => optional($b->expiry_date)->toDateString(),
                'days_left' => now()->startOfDay()->diffInDays($b->expiry_date, false),
                'remaining_qty' => (float) $b->remaining_qty,
                'unit_cost' => (float) $b->unit_cost,
                'item' => $b->item ? [
                    'id' => $b->item->id,
                    'sku' => $b->item->sku,
                    'name' => $b->item->name,
                    'unit' => $b->item->baseUnit?->symbol,
                ] : null,
                'location' => $b->location ? ['id' => $b->location->id, 'name' => $b->location->name] : null,
            ]);

        return response()->success($batches);
    }
}
