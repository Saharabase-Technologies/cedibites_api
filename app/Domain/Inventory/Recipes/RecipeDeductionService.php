<?php

namespace App\Domain\Inventory\Recipes;

use App\Domain\Inventory\Batches\BatchService;
use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Models\Inventory\Location;
use App\Models\Inventory\Recipe;
use App\Models\Inventory\StockMovement;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Automatic stock deduction driven by recipes/BOM. When an order is paid, each
 * order line's option recipe is resolved and its ingredients are deducted from
 * the central warehouse (negative `sale` movements). Fully idempotent per order
 * line + item, so re-firing the payment-confirmed signal never double-deducts.
 *
 * MVP simplifications (see plan): deduct from the single warehouse; recipe
 * ingredient quantities are taken in the item's base unit (no unit conversion).
 */
class RecipeDeductionService
{
    public function __construct(
        private readonly MovementPostingEngine $posting,
        private readonly BatchService $batches,
    ) {}

    /** Deduct ingredients for every line of a paid order. */
    public function deductForOrder(Order $order): void
    {
        // Idempotent: if this order already deducted, do nothing (safe re-fire).
        $already = StockMovement::where('reference_type', 'order')
            ->where('reference_id', $order->id)
            ->where('movement_type', 'sale')
            ->exists();
        if ($already) {
            return;
        }

        $location = $this->warehouseLocation();
        if (! $location) {
            Log::warning('Recipe deduction skipped: no active warehouse location.', ['order_id' => $order->id]);

            return;
        }

        $order->loadMissing('items');

        // Aggregate ingredient demand per item across all order lines.
        $demand = []; // item_id => qty
        foreach ($order->items as $line) {
            $recipe = $this->resolveRecipe((int) $line->menu_item_option_id, $order->branch_id);
            if (! $recipe) {
                continue; // option without a recipe → logged no-op
            }
            $yield = max((float) $recipe->yield_qty, 0.0001);
            $portions = (float) $line->quantity / $yield;
            foreach ($recipe->ingredients as $ing) {
                $itemId = (int) $ing->item_id;
                $demand[$itemId] = ($demand[$itemId] ?? 0) + ((float) $ing->quantity * $portions);
            }
        }

        if (empty($demand)) {
            return;
        }

        DB::transaction(function () use ($demand, $location, $order) {
            foreach ($demand as $itemId => $qty) {
                $qty = round($qty, 4);
                if ($qty <= 0) {
                    continue;
                }

                // FEFO: consume soonest-expiring batches first (one movement per
                // source batch). Untracked items return a single null-batch entry.
                $allocations = $this->batches->allocate((int) $itemId, $location->id, $qty);

                foreach ($allocations as $alloc) {
                    $cost = $alloc['unit_cost'];
                    if ($cost === null) {
                        $avg = DB::table('inventory_stock_balances')
                            ->where('item_id', $itemId)->where('location_id', $location->id)
                            ->value('weighted_avg_cost');
                        $cost = $avg !== null ? (float) $avg : null;
                    }

                    $this->posting->post([
                        'item_id' => $itemId,
                        'location_id' => $location->id,
                        'quantity' => -1 * $alloc['qty'], // sale = stock out
                        'movement_type' => 'sale',
                        'reference_type' => 'order',
                        'reference_id' => $order->id,
                        'batch_id' => $alloc['batch_id'],
                        'unit_cost_at_time' => $cost,
                        'idempotency_key' => "sale:order:{$order->id}:item:{$itemId}:batch:".($alloc['batch_id'] ?? 0),
                        'occurred_at' => now(),
                    ]);
                }

                // The sale already happened — a negative balance is a signal, not a
                // blocker. Surface it for the warehouse team to reconcile.
                $balance = DB::table('inventory_stock_balances')
                    ->where('item_id', $itemId)->where('location_id', $location->id)->value('quantity');
                if ($balance !== null && (float) $balance < 0) {
                    Log::warning('Recipe deduction drove stock negative.', [
                        'order_id' => $order->id, 'item_id' => $itemId, 'balance' => $balance,
                    ]);
                }
            }
        });
    }

    /** Reverse a previously-deducted order (e.g. cancellation/refund). */
    public function reverseForOrder(Order $order): void
    {
        // Idempotent: don't reverse twice.
        $alreadyReversed = StockMovement::where('reference_type', 'order')
            ->where('reference_id', $order->id)
            ->where('movement_type', 'reversal')
            ->exists();
        if ($alreadyReversed) {
            return;
        }

        $sales = StockMovement::query()
            ->where('reference_type', 'order')
            ->where('reference_id', $order->id)
            ->where('movement_type', 'sale')
            ->get();

        DB::transaction(function () use ($sales, $order) {
            foreach ($sales as $sale) {
                $addBack = -1 * (float) $sale->quantity; // sale qty is negative → positive add-back

                $this->posting->post([
                    'item_id' => $sale->item_id,
                    'location_id' => $sale->location_id,
                    'quantity' => $addBack,
                    'movement_type' => 'reversal',
                    'reference_type' => 'order',
                    'reference_id' => $order->id,
                    'batch_id' => $sale->batch_id,
                    'unit_cost_at_time' => $sale->unit_cost_at_time !== null ? (float) $sale->unit_cost_at_time : null,
                    'parent_movement_id' => $sale->id,
                    'idempotency_key' => "reversal:movement:{$sale->id}",
                    'occurred_at' => now(),
                ]);

                // Return the consumed quantity to its FEFO batch.
                $this->batches->restore($sale->batch_id, $addBack);
            }
        });
    }

    /**
     * Active recipe for an option: branch override first, else global default.
     * Locked recipes win over drafts.
     */
    private function resolveRecipe(int $optionId, ?int $branchId): ?Recipe
    {
        return Recipe::query()
            ->with('ingredients')
            ->where('menu_item_option_id', $optionId)
            ->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'))
            ->orderByRaw('branch_id IS NULL') // branch-specific (NOT NULL) first
            ->orderByRaw("status = 'locked' DESC")
            ->first();
    }

    private function warehouseLocation(): ?Location
    {
        return Location::query()
            ->where('type', 'warehouse')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }
}
