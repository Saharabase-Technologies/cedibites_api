<?php

namespace App\Domain\Inventory\Recipes;

use App\Domain\Inventory\Batches\BatchService;
use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Models\Inventory\Alert;
use App\Models\Inventory\Location;
use App\Models\Inventory\Recipe;
use App\Models\Inventory\StockMovement;
use App\Models\Order;
use App\Models\OrderRound;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Automatic stock deduction driven by recipes/BOM. When an order is paid, each
 * order line's option recipe is resolved and its ingredients are deducted
 * (negative `sale` movements) from the location that actually fulfilled the sale:
 * the order's own branch. A branch with no inventory location mapped yet falls
 * back to the central warehouse (logged), so the transition degrades gracefully.
 * Fully idempotent per order + item, so re-firing the payment-confirmed signal
 * never double-deducts.
 *
 * MVP simplification: recipe ingredient quantities are taken in the item's base
 * unit (no unit conversion).
 */
class RecipeDeductionService
{
    public function __construct(
        private readonly MovementPostingEngine $posting,
        private readonly BatchService $batches,
    ) {}

    /**
     * Deduct ingredients for every line of a paid order, as it was placed.
     * Items added later belong to a round and go through deductForRound, so
     * the two never take the same food twice.
     */
    public function deductForOrder(Order $order): void
    {
        $order->loadMissing('items');

        $this->deduct($order, 'order', $order->id, $order->items->whereNull('order_round_id'));
    }

    /**
     * Deduct ingredients for a round added to an order (Phase 4b). Once per
     * round, whatever calls it.
     */
    public function deductForRound(OrderRound $round): void
    {
        $round->loadMissing(['order', 'items']);

        $this->deduct($round->order, 'order_round', $round->id, $round->items);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\OrderItem>  $lines
     */
    private function deduct(Order $order, string $referenceType, int $referenceId, $lines): void
    {
        // Idempotent: if this order already deducted, do nothing (safe re-fire).
        $already = StockMovement::where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('movement_type', 'sale')
            ->exists();
        if ($already) {
            return;
        }

        $location = $this->resolveDeductionLocation($order);
        if (! $location) {
            Log::warning('Recipe deduction skipped: no inventory location for the order.', [
                'order_id' => $order->id, 'branch_id' => $order->branch_id,
            ]);

            return;
        }

        // Aggregate ingredient demand per item across all order lines.
        $demand = []; // item_id => qty
        foreach ($lines as $line) {
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

        DB::transaction(function () use ($demand, $location, $order, $referenceType, $referenceId) {
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
                        'reference_type' => $referenceType,
                        'reference_id' => $referenceId,
                        'batch_id' => $alloc['batch_id'],
                        'unit_cost_at_time' => $cost,
                        // "sale:order:{id}" for an order, as it has always been.
                        'idempotency_key' => "sale:{$referenceType}:{$referenceId}:item:{$itemId}:batch:".($alloc['batch_id'] ?? 0),
                        'occurred_at' => now(),
                    ]);
                }

                // The sale already happened - a negative balance is a signal, not a
                // blocker. Surface it for the warehouse team to reconcile: log it and
                // raise a portal-visible alert (deduped per item+location while open).
                $balance = DB::table('inventory_stock_balances')
                    ->where('item_id', $itemId)->where('location_id', $location->id)->value('quantity');
                if ($balance !== null && (float) $balance < 0) {
                    Log::warning('Recipe deduction drove stock negative.', [
                        'order_id' => $order->id, 'item_id' => $itemId, 'balance' => $balance,
                    ]);
                    Alert::raiseNegativeStock((int) $itemId, $location->id, (float) $balance, $order->id);
                }
            }
        });
    }

    /**
     * Reverse a previously-deducted order (e.g. cancellation/refund), its
     * rounds included. Each sale movement is reversed once: one already
     * reversed is skipped, so a second call does nothing.
     */
    public function reverseForOrder(Order $order): void
    {
        $roundIds = OrderRound::where('order_id', $order->id)->pluck('id');

        $this->reverse(fn ($q) => $q
            ->where(fn ($o) => $o->where('reference_type', 'order')->where('reference_id', $order->id))
            ->orWhere(fn ($r) => $r->where('reference_type', 'order_round')->whereIn('reference_id', $roundIds)));
    }

    /**
     * Give back one round's stock, when the round alone is cancelled (D45).
     * The rest of the order is still being eaten, so nothing else moves.
     */
    public function reverseForRound(OrderRound $round): void
    {
        $this->reverse(fn ($q) => $q->where('reference_type', 'order_round')->where('reference_id', $round->id));
    }

    /** Reverse each sale movement the scope picks out, once. */
    private function reverse(\Closure $scope): void
    {
        $sales = StockMovement::query()
            ->where('movement_type', 'sale')
            ->where($scope)
            ->whereNotIn('id', StockMovement::query()
                ->where('movement_type', 'reversal')
                ->whereNotNull('parent_movement_id')
                ->select('parent_movement_id'))
            ->get();

        if ($sales->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($sales) {
            foreach ($sales as $sale) {
                $addBack = -1 * (float) $sale->quantity; // sale qty is negative → positive add-back

                $this->posting->post([
                    'item_id' => $sale->item_id,
                    'location_id' => $sale->location_id,
                    'quantity' => $addBack,
                    'movement_type' => 'reversal',
                    'reference_type' => $sale->reference_type,
                    'reference_id' => $sale->reference_id,
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

    /**
     * The location a sale is deducted from: the order's branch (now that branch
     * stock exists via transfers/requisitions).
     *
     * A branch with no inventory location still falls back to the warehouse, so
     * a roll-out never stops deducting — but it raises a critical alert while it
     * does. The fallback means the branch's sales eat the mother kitchen's
     * stock: neither figure is then true, and the longer it goes unnoticed the
     * less the ledger is worth. It used to be a Log::info nobody reads.
     */
    private function resolveDeductionLocation(Order $order): ?Location
    {
        if ($order->branch_id) {
            $branchLocation = Location::query()
                ->where('branch_id', $order->branch_id)
                ->where('is_active', true)
                ->orderBy('id')
                ->first();

            if ($branchLocation) {
                return $branchLocation;
            }

            $fallback = $this->warehouseLocation();

            Log::warning('Recipe deduction: branch has no inventory location, falling back to warehouse.', [
                'order_id' => $order->id,
                'branch_id' => $order->branch_id,
                'fallback_location_id' => $fallback?->id,
            ]);

            if ($fallback) {
                Alert::raiseMisroutedDeduction(
                    (int) $order->branch_id,
                    $order->branch?->name ?? "Branch #{$order->branch_id}",
                    $fallback,
                    $order->id,
                );
            }

            return $fallback;
        }

        return $this->warehouseLocation();
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
