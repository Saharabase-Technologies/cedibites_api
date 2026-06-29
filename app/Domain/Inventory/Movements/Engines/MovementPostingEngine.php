<?php

namespace App\Domain\Inventory\Movements\Engines;

use App\Models\Inventory\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single writer to the ledger. Atomically appends a movement and updates
 * the denormalized balance row under a row lock. Idempotent via idempotency_key.
 *
 * Never call outside a queue/HTTP request that can tolerate a DB transaction.
 */
class MovementPostingEngine
{
    public function __construct(
        private readonly WeightedAverageCostCalculator $costCalculator,
    ) {}

    /**
     * @param  array{
     *     item_id:int,
     *     location_id:int,
     *     quantity:float,
     *     movement_type:string,
     *     idempotency_key:string,
     *     reference_type?:string|null,
     *     reference_id?:int|null,
     *     unit_cost_at_time?:float|null,
     *     user_id?:int|null,
     *     parent_movement_id?:int|null,
     *     occurred_at?:\DateTimeInterface|string|null,
     * }  $data
     */
    public function post(array $data): StockMovement
    {
        return DB::transaction(function () use ($data) {
            // Idempotency: if this business operation already posted, return it.
            $existing = StockMovement::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing;
            }

            $itemId = (int) $data['item_id'];
            $locationId = (int) $data['location_id'];
            $quantity = (float) $data['quantity'];
            $unitCost = array_key_exists('unit_cost_at_time', $data) ? $data['unit_cost_at_time'] : null;

            // Lock the balance row (or establish a zero baseline).
            $balance = DB::table('inventory_stock_balances')
                ->where('item_id', $itemId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->first();

            $currentQty = $balance ? (float) $balance->quantity : 0.0;
            $currentCost = $balance ? (float) $balance->weighted_avg_cost : 0.0;

            $occurredAt = isset($data['occurred_at'])
                ? Carbon::parse($data['occurred_at'])
                : now();

            $movement = StockMovement::create([
                'item_id' => $itemId,
                'location_id' => $locationId,
                'quantity' => $quantity,
                'movement_type' => $data['movement_type'],
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'unit_cost_at_time' => $unitCost,
                'user_id' => $data['user_id'] ?? null,
                'parent_movement_id' => $data['parent_movement_id'] ?? null,
                'idempotency_key' => $data['idempotency_key'],
                'occurred_at' => $occurredAt,
            ]);

            $newQty = round($currentQty + $quantity, 4);
            $newCost = $this->costCalculator->next($currentQty, $currentCost, $quantity, $unitCost === null ? null : (float) $unitCost);

            DB::table('inventory_stock_balances')->updateOrInsert(
                ['item_id' => $itemId, 'location_id' => $locationId],
                [
                    'quantity' => $newQty,
                    'weighted_avg_cost' => $newCost,
                    'last_movement_id' => $movement->id,
                    'updated_at' => now(),
                ],
            );

            return $movement;
        });
    }
}
