<?php

namespace App\Domain\Inventory\Purchases;

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Domain\Inventory\Support\ReferenceGenerator;
use App\Enums\Inventory\PurchaseOrderStatus;
use App\Models\Inventory\Item;
use App\Models\Inventory\Purchase;
use App\Models\Inventory\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PurchaseService
{
    public function __construct(
        private readonly ReferenceGenerator $references,
        private readonly MovementPostingEngine $posting,
        private readonly \App\Domain\Inventory\Batches\BatchService $batches,
    ) {}

    /**
     * Record a supplier receipt: persists the purchase, posts a `purchase`
     * movement per line into the destination warehouse (updating balance +
     * weighted-average cost), and advances the linked PO.
     *
     * @param  array<string,mixed>  $data
     */
    public function receive(array $data, User $actor): Purchase
    {
        $isUrgent = (bool) ($data['is_urgent_buy'] ?? false);

        // Strict mode: every purchase must tie to a PO unless it is an urgent buy.
        if (! $isUrgent && empty($data['purchase_order_id'])) {
            throw new InventoryException('A purchase must reference a purchase order unless it is an urgent buy.');
        }

        $po = null;
        if (! empty($data['purchase_order_id'])) {
            $po = PurchaseOrder::with('items')->findOrFail($data['purchase_order_id']);
            if (! $po->status->isReceivable()) {
                throw new InventoryException("Purchase order {$po->reference} is not open for receiving (status: {$po->status->value}).");
            }
        }

        return DB::transaction(function () use ($data, $actor, $po, $isUrgent) {
            $itemIds = array_column($data['items'], 'item_id');
            $itemModels = Item::whereIn('id', $itemIds)->get()->keyBy('id');
            $units = $itemModels->map(fn ($i) => $i->base_unit_id);
            $poLines = $po ? $po->items->keyBy('id') : collect();

            $purchase = Purchase::create([
                'reference' => $this->references->purchase(),
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'supplier_name' => $data['supplier_name'] ?? null,
                'destination_location_id' => $data['destination_location_id'],
                'is_urgent_buy' => $isUrgent,
                'urgent_buy_reason' => $isUrgent ? ($data['urgent_buy_reason'] ?? null) : null,
                'invoice_number' => $data['invoice_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor->id,
                'total_paid' => 0,
                'received_at' => $data['received_at'],
            ]);

            $total = 0.0;

            foreach ($data['items'] as $row) {
                $itemId = (int) $row['item_id'];
                if (! isset($units[$itemId])) {
                    throw new InventoryException("Inventory item {$itemId} does not exist.");
                }

                $received = (float) $row['received_qty'];
                $cost = (float) $row['unit_cost_paid'];
                $poItemId = $row['purchase_order_item_id'] ?? null;

                // Expected qty: explicit, else the PO line's outstanding amount.
                $ordered = $row['ordered_qty'] ?? null;
                if ($ordered === null && $poItemId && $poLines->has($poItemId)) {
                    $ordered = $poLines[$poItemId]->outstandingQty();
                }
                $variance = $ordered !== null ? round($received - (float) $ordered, 4) : null;

                // Expected unit cost: explicit, else the PO line's estimated cost.
                $expectedCost = $row['expected_unit_cost'] ?? null;
                if ($expectedCost === null && $poItemId && $poLines->has($poItemId)) {
                    $expectedCost = (float) $poLines[$poItemId]->estimated_unit_cost;
                }
                $costVariance = $expectedCost !== null ? round($cost - (float) $expectedCost, 4) : null;

                $lineTotal = round($received * $cost, 4);
                $total += $lineTotal;

                $purchaseItem = $purchase->items()->create([
                    'item_id' => $itemId,
                    'unit_id' => $units[$itemId],
                    'purchase_order_item_id' => $poItemId,
                    'ordered_qty' => $ordered,
                    'received_qty' => $received,
                    'variance' => $variance,
                    'variance_reason' => $row['variance_reason'] ?? null,
                    'expected_unit_cost' => $expectedCost,
                    'cost_variance' => $costVariance,
                    'unit_cost_paid' => $cost,
                    'line_total' => $lineTotal,
                ]);

                // For expiry-tracked items, record a FEFO batch for this lot.
                $batch = $this->batches->recordReceipt(
                    $itemModels[$itemId],
                    (int) $purchase->destination_location_id,
                    $received,
                    $cost,
                    $row['expiry_date'] ?? null,
                    $purchaseItem->id,
                    $purchase->received_at,
                );

                // Post the receipt into the warehouse ledger + balance.
                $this->posting->post([
                    'item_id' => $itemId,
                    'location_id' => (int) $purchase->destination_location_id,
                    'quantity' => $received,
                    'movement_type' => 'purchase',
                    'reference_type' => 'inventory_purchase_item',
                    'reference_id' => $purchaseItem->id,
                    'batch_id' => $batch?->id,
                    'unit_cost_at_time' => $cost,
                    'user_id' => $actor->id,
                    'idempotency_key' => "purchase_item:{$purchaseItem->id}",
                    'occurred_at' => $purchase->received_at,
                ]);

                // Keep the item master's weighted-average cost in step with the
                // destination balance (single-warehouse MVP).
                $balanceCost = DB::table('inventory_stock_balances')
                    ->where('item_id', $itemId)
                    ->where('location_id', $purchase->destination_location_id)
                    ->value('weighted_avg_cost');
                if ($balanceCost !== null) {
                    Item::whereKey($itemId)->update(['weighted_avg_cost' => $balanceCost]);
                }

                // Advance the PO line.
                if ($poItemId && $poLines->has($poItemId)) {
                    $poLine = $poLines[$poItemId];
                    $poLine->received_qty = round((float) $poLine->received_qty + $received, 4);
                    $poLine->save();
                }
            }

            $purchase->total_paid = round($total, 4);
            $purchase->save();

            if ($po) {
                $this->advancePurchaseOrder($po);
            }

            return $purchase;
        });
    }

    private function advancePurchaseOrder(PurchaseOrder $po): void
    {
        $po->load('items');

        $allReceived = $po->items->isNotEmpty()
            && $po->items->every(fn ($l) => (float) $l->received_qty >= (float) $l->ordered_qty);
        $anyReceived = $po->items->contains(fn ($l) => (float) $l->received_qty > 0);

        if ($allReceived) {
            $po->status = PurchaseOrderStatus::Received;
        } elseif ($anyReceived) {
            $po->status = PurchaseOrderStatus::PartiallyReceived;
        }

        $po->actual_total = round((float) $po->purchases()->sum('total_paid'), 4);
        $po->save();
    }
}
