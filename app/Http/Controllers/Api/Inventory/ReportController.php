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

    /**
     * What the kitchen actually used on a given day, and which sales used it.
     *
     * Reads the `sale` movements the recipe deduction writes, so it is the
     * ledger's own account of consumption rather than a projection from orders —
     * if a dish sold without deducting (no recipe, or a branch with no location)
     * it is honestly absent here rather than silently assumed.
     *
     * Grouped by item, because "how much chicken went today" is the question
     * being asked; the orders that consumed it hang off each line so a figure
     * that looks wrong can be traced back to the sales that produced it.
     *
     * Scoped to the caller's locations — a branch manager sees his own kitchen's
     * consumption, not the company's.
     */
    public function dailyConsumption(Request $request): JsonResponse
    {
        $date = $request->filled('date')
            ? \Illuminate\Support\Carbon::parse($request->string('date')->toString())->toDateString()
            : now()->toDateString();

        $movements = \App\Models\Inventory\StockMovement::query()
            ->visibleTo($request->user())
            ->with(['item:id,sku,name,base_unit_id', 'item.baseUnit:id,symbol', 'location:id,name'])
            ->where('movement_type', 'sale')
            ->whereDate('occurred_at', $date)
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->orderBy('occurred_at')
            ->get();

        // Sale movements are negative; consumption reads better as a positive.
        $orderNumbers = $this->orderNumbersFor($movements->pluck('reference_id')->filter()->unique()->all());

        $items = $movements
            ->groupBy('item_id')
            ->map(function ($rows) use ($orderNumbers) {
                $first = $rows->first();
                $orders = $rows
                    ->filter(fn ($m) => $m->reference_type === 'order' && $m->reference_id)
                    ->groupBy('reference_id')
                    ->map(fn ($forOrder, $orderId) => [
                        'order_id' => (int) $orderId,
                        'order_number' => $orderNumbers[(int) $orderId] ?? null,
                        'quantity' => round(abs((float) $forOrder->sum('quantity')), 4),
                        'at' => optional($forOrder->first()->occurred_at)->toIso8601String(),
                    ])
                    ->sortBy('at')
                    ->values();

                return [
                    'item_id' => (int) $first->item_id,
                    'sku' => $first->item?->sku,
                    'name' => $first->item?->name ?? "Item #{$first->item_id}",
                    'unit' => $first->item?->baseUnit?->symbol,
                    'location' => $first->location?->name,
                    'quantity' => round(abs((float) $rows->sum('quantity')), 4),
                    'movements' => $rows->count(),
                    'orders' => $orders,
                ];
            })
            ->sortByDesc('quantity')
            ->values();

        return response()->success([
            'date' => $date,
            'items' => $items,
            'totals' => [
                'items' => $items->count(),
                'orders' => $movements->pluck('reference_id')->filter()->unique()->count(),
            ],
        ]);
    }

    /**
     * @param  array<int, int>  $orderIds
     * @return array<int, string>
     */
    private function orderNumbersFor(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return \App\Models\Order::query()
            ->whereIn('id', $orderIds)
            ->pluck('order_number', 'id')
            ->all();
    }
}
