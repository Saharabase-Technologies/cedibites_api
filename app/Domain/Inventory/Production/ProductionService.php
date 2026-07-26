<?php

namespace App\Domain\Inventory\Production;

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Models\Inventory\Item;
use App\Models\Inventory\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records the mother kitchen consuming raw materials (production usage). Each
 * line posts a negative `production` movement into the warehouse ledger via the
 * posting engine, valued at the item's current weighted-average cost - the
 * manual counterpart of the eventual BOM-driven auto-deduction on sale.
 */
class ProductionService
{
    public function __construct(
        private readonly MovementPostingEngine $posting,
        private readonly \App\Domain\Inventory\Batches\BatchService $batches,
    ) {}

    /**
     * @param  array{location_id:int, occurred_at:string, items:array<int,array{item_id:int, quantity:float}>}  $data
     * @return array<int, StockMovement>
     */
    public function record(array $data, User $actor): array
    {
        return DB::transaction(function () use ($data, $actor) {
            $locationId = (int) $data['location_id'];
            $ref = (string) Str::uuid();
            $itemIds = array_column($data['items'], 'item_id');

            // Lock balances so the availability check and the post are consistent.
            $balances = DB::table('inventory_stock_balances')
                ->where('location_id', $locationId)
                ->whereIn('item_id', $itemIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('item_id');

            $movements = [];

            foreach ($data['items'] as $row) {
                $itemId = (int) $row['item_id'];
                $qty = (float) $row['quantity'];

                if ($qty <= 0) {
                    throw new InventoryException('Consumption quantity must be greater than zero.');
                }

                $balance = $balances[$itemId] ?? null;
                $onHand = $balance ? (float) $balance->quantity : 0.0;

                if ($qty > $onHand) {
                    $name = Item::whereKey($itemId)->value('name') ?? "item {$itemId}";
                    throw new InventoryException(
                        "Not enough stock of {$name}: {$onHand} on hand, tried to use {$qty}."
                    );
                }

                // FEFO: consume soonest-expiring batches first (one movement per
                // source batch). Untracked items yield a single null-batch entry.
                $avgCost = $balance ? (float) $balance->weighted_avg_cost : null;
                foreach ($this->batches->allocate($itemId, $locationId, $qty) as $alloc) {
                    $movements[] = $this->posting->post([
                        'item_id' => $itemId,
                        'location_id' => $locationId,
                        'quantity' => -1 * $alloc['qty'], // negative = stock out
                        'movement_type' => 'production',
                        'reference_type' => 'production',
                        'reference_id' => null,
                        'batch_id' => $alloc['batch_id'],
                        'unit_cost_at_time' => $alloc['unit_cost'] ?? $avgCost,
                        'user_id' => $actor->id,
                        'idempotency_key' => "production:{$ref}:{$itemId}:batch:".($alloc['batch_id'] ?? 0),
                        'occurred_at' => $data['occurred_at'],
                    ]);
                }
            }

            return $movements;
        });
    }
}
