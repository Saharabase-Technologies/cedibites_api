<?php

namespace App\Domain\Inventory\Production;

use App\Domain\Inventory\Batches\BatchService;
use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Domain\Inventory\Support\ReferenceGenerator;
use App\Models\Inventory\Item;
use App\Models\Inventory\ProductionLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mother-kitchen production: a batch that consumes raw inputs (FEFO) and yields a
 * prepared output item added to warehouse stock, costed by the inputs
 * (output_unit_cost = total input cost ÷ output qty). Both sides post `production`
 * movements referencing the production log.
 */
class ProductionRunService
{
    public function __construct(
        private readonly MovementPostingEngine $posting,
        private readonly BatchService $batches,
        private readonly ReferenceGenerator $references,
    ) {}

    /**
     * @param  array{
     *   location_id:int, output_item_id:int, output_qty:float, output_unit_id?:int,
     *   expiry_date?:?string, notes?:?string, occurred_at?:?string,
     *   inputs:array<int,array{item_id:int, quantity:float}>
     * }  $data
     */
    public function record(array $data, User $actor): ProductionLog
    {
        $locationId = (int) $data['location_id'];
        $outputQty = (float) $data['output_qty'];
        if ($outputQty <= 0) {
            throw new InventoryException('Output quantity must be greater than zero.');
        }

        $outputItem = Item::findOrFail($data['output_item_id']);
        $occurredAt = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();

        return DB::transaction(function () use ($data, $actor, $locationId, $outputItem, $outputQty, $occurredAt) {
            // You can't cook with stock you don't have. Lock the input balances and
            // validate inside the transaction so the check holds against concurrent
            // consumption (the lock must outlive the check, hence not before the tx).
            $inputItemIds = array_column($data['inputs'], 'item_id');
            $balances = DB::table('inventory_stock_balances')
                ->where('location_id', $locationId)
                ->whereIn('item_id', $inputItemIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('item_id');

            foreach ($data['inputs'] as $row) {
                $qty = (float) $row['quantity'];
                if ($qty <= 0) {
                    throw new InventoryException('Input quantity must be greater than zero.');
                }
                $onHand = isset($balances[$row['item_id']]) ? (float) $balances[$row['item_id']]->quantity : 0.0;
                if ($qty > $onHand) {
                    $name = Item::whereKey($row['item_id'])->value('name') ?? "item {$row['item_id']}";
                    throw new InventoryException("Not enough stock of {$name}: {$onHand} on hand, need {$qty}.");
                }
            }

            $log = ProductionLog::create([
                'reference' => $this->references->production(),
                'location_id' => $locationId,
                'output_item_id' => $outputItem->id,
                'output_unit_id' => $data['output_unit_id'] ?? $outputItem->base_unit_id,
                'output_qty' => $outputQty,
                'output_batch_id' => null,
                'input_cost_total' => 0,
                'output_unit_cost' => 0,
                'notes' => $data['notes'] ?? null,
                'produced_by' => $actor->id,
                'produced_at' => $occurredAt,
            ]);

            $inputCostTotal = 0.0;

            foreach ($data['inputs'] as $row) {
                $itemId = (int) $row['item_id'];
                $qty = (float) $row['quantity'];
                $lineCost = 0.0;

                // FEFO consume: one movement per source batch.
                foreach ($this->batches->allocate($itemId, $locationId, $qty) as $alloc) {
                    $cost = $alloc['unit_cost'];
                    if ($cost === null) {
                        $avg = DB::table('inventory_stock_balances')
                            ->where('item_id', $itemId)->where('location_id', $locationId)
                            ->value('weighted_avg_cost');
                        $cost = $avg !== null ? (float) $avg : 0.0;
                    }
                    $lineCost += $alloc['qty'] * (float) $cost;

                    $this->posting->post([
                        'item_id' => $itemId,
                        'location_id' => $locationId,
                        'quantity' => -1 * $alloc['qty'],
                        'movement_type' => 'production',
                        'reference_type' => 'inventory_production_log',
                        'reference_id' => $log->id,
                        'batch_id' => $alloc['batch_id'],
                        'unit_cost_at_time' => (float) $cost,
                        'user_id' => $actor->id,
                        'idempotency_key' => "production_in:log:{$log->id}:item:{$itemId}:batch:".($alloc['batch_id'] ?? 0),
                        'occurred_at' => $occurredAt,
                    ]);
                }

                $lineCost = round($lineCost, 4);
                $inputCostTotal += $lineCost;

                $itemUnit = Item::whereKey($itemId)->value('base_unit_id');
                $log->inputs()->create([
                    'item_id' => $itemId,
                    'unit_id' => $itemUnit,
                    'quantity' => $qty,
                    'unit_cost_at_time' => $qty > 0 ? round($lineCost / $qty, 4) : 0,
                    'line_cost' => $lineCost,
                ]);
            }

            $inputCostTotal = round($inputCostTotal, 4);
            $outputUnitCost = $outputQty > 0 ? round($inputCostTotal / $outputQty, 4) : 0.0;

            // A tracked output becomes its own FEFO batch.
            $batch = $this->batches->recordReceipt(
                $outputItem,
                $locationId,
                $outputQty,
                $outputUnitCost,
                $data['expiry_date'] ?? null,
                null,
                $occurredAt,
            );

            $this->posting->post([
                'item_id' => $outputItem->id,
                'location_id' => $locationId,
                'quantity' => $outputQty, // produced = stock in
                'movement_type' => 'production',
                'reference_type' => 'inventory_production_log',
                'reference_id' => $log->id,
                'batch_id' => $batch?->id,
                'unit_cost_at_time' => $outputUnitCost,
                'user_id' => $actor->id,
                'idempotency_key' => "production_out:log:{$log->id}",
                'occurred_at' => $occurredAt,
            ]);

            $log->update([
                'input_cost_total' => $inputCostTotal,
                'output_unit_cost' => $outputUnitCost,
                'output_batch_id' => $batch?->id,
            ]);

            return $log;
        });
    }
}
