<?php

namespace App\Domain\Inventory\PurchaseOrders;

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Support\ReferenceGenerator;
use App\Enums\Inventory\PurchaseOrderStatus;
use App\Models\Inventory\Item;
use App\Models\Inventory\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    public function __construct(
        private readonly ReferenceGenerator $references,
    ) {}

    /**
     * Create a draft PO with line items. Computes totals + approval flag.
     *
     * @param  array{supplier_id:int,destination_location_id:int,expected_delivery_date?:string|null,notes?:string|null,items:array<int,array{item_id:int,ordered_qty:float,estimated_unit_cost:float}>}  $data
     */
    public function create(array $data, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $actor) {
            $lines = $this->buildLines($data['items']);
            $estimatedTotal = $this->sumLines($lines);

            $po = PurchaseOrder::create([
                'reference' => $this->references->purchaseOrder(),
                'verification_code' => $this->generateVerificationCode(),
                'supplier_id' => $data['supplier_id'],
                'destination_location_id' => $data['destination_location_id'],
                'status' => PurchaseOrderStatus::Draft,
                'requires_approval' => $this->requiresApproval($estimatedTotal),
                'estimated_total' => $estimatedTotal,
                'actual_total' => 0,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            $po->items()->createMany($lines);

            return $po;
        });
    }

    /**
     * Replace a draft PO's contents. Only draft POs are editable.
     */
    public function update(PurchaseOrder $po, array $data): PurchaseOrder
    {
        if (! $po->status->isEditable()) {
            throw new InventoryException("Only draft purchase orders can be edited (current status: {$po->status->value}).");
        }

        return DB::transaction(function () use ($po, $data) {
            $this->applyEdits($po, $data);
            $po->save();

            return $po;
        });
    }

    /**
     * Approver edits a PO awaiting approval and approves it in one step.
     * Because the approver is the financial authority, saving the edit sends
     * the PO straight to the supplier (status `sent`).
     */
    public function editAndApprove(PurchaseOrder $po, array $data, User $actor): PurchaseOrder
    {
        if ($po->status !== PurchaseOrderStatus::PendingApproval) {
            throw new InventoryException("Only purchase orders awaiting approval can be edited and approved (current status: {$po->status->value}).");
        }

        return DB::transaction(function () use ($po, $data, $actor) {
            $this->applyEdits($po, $data);
            $po->status = PurchaseOrderStatus::Sent;
            $po->approved_by = $actor->id;
            $po->approved_at = now();
            $po->save();

            return $po;
        });
    }

    /**
     * Apply line + meta edits to a PO model (without saving or touching status).
     * Recomputes the estimated total and approval flag when lines change.
     */
    private function applyEdits(PurchaseOrder $po, array $data): void
    {
        if (array_key_exists('items', $data)) {
            $lines = $this->buildLines($data['items']);
            $po->items()->delete();
            $po->items()->createMany($lines);
            $po->estimated_total = $this->sumLines($lines);
            $po->requires_approval = $this->requiresApproval((float) $po->estimated_total);
        }

        $po->fill(array_filter([
            'supplier_id' => $data['supplier_id'] ?? null,
            'destination_location_id' => $data['destination_location_id'] ?? null,
        ], fn ($v) => $v !== null));

        if (array_key_exists('expected_delivery_date', $data)) {
            $po->expected_delivery_date = $data['expected_delivery_date'];
        }
        if (array_key_exists('notes', $data)) {
            $po->notes = $data['notes'];
        }
    }

    /**
     * draft → sent (under threshold) or pending_approval (at/above threshold).
     */
    public function submit(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== PurchaseOrderStatus::Draft) {
            throw new InventoryException("Only draft purchase orders can be submitted (current status: {$po->status->value}).");
        }

        $po->requires_approval = $this->requiresApproval((float) $po->estimated_total);
        $po->status = $po->requires_approval
            ? PurchaseOrderStatus::PendingApproval
            : PurchaseOrderStatus::Sent;
        $po->save();

        return $po;
    }

    /**
     * pending_approval → sent. Admin-only (enforced at the route).
     */
    public function approve(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        if ($po->status !== PurchaseOrderStatus::PendingApproval) {
            throw new InventoryException("Only purchase orders awaiting approval can be approved (current status: {$po->status->value}).");
        }

        $po->status = PurchaseOrderStatus::Sent;
        $po->approved_by = $actor->id;
        $po->approved_at = now();
        $po->save();

        return $po;
    }

    public function cancel(PurchaseOrder $po, User $actor, string $reason): PurchaseOrder
    {
        if (! $po->status->isCancellable()) {
            throw new InventoryException("This purchase order can no longer be cancelled (current status: {$po->status->value}).");
        }

        $po->status = PurchaseOrderStatus::Cancelled;
        $po->cancelled_by = $actor->id;
        $po->cancelled_at = now();
        $po->cancel_reason = $reason;
        $po->save();

        return $po;
    }

    public function close(PurchaseOrder $po): PurchaseOrder
    {
        if (! $po->status->isClosable()) {
            throw new InventoryException("Only received or partially-received purchase orders can be closed (current status: {$po->status->value}).");
        }

        $po->status = PurchaseOrderStatus::Closed;
        $po->save();

        return $po;
    }

    public function requiresApproval(float $estimatedTotal): bool
    {
        return $estimatedTotal >= (float) config('inventory.po_approval_threshold');
    }

    /**
     * Unguessable, human-readable anti-forgery code (e.g. K7M4-2QXP-9HJF).
     * Uses a Crockford-style alphabet (no 0/O/1/I) over CSPRNG bytes.
     */
    private function generateVerificationCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $groups = [];
            for ($g = 0; $g < 3; $g++) {
                $chunk = '';
                for ($i = 0; $i < 4; $i++) {
                    $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                }
                $groups[] = $chunk;
            }
            $code = implode('-', $groups);
        } while (PurchaseOrder::withTrashed()->where('verification_code', $code)->exists());

        return $code;
    }

    /**
     * Normalize incoming item rows into persistable line attributes, deriving
     * unit_id from each item's base unit and computing line_total.
     *
     * @param  array<int,array{item_id:int,ordered_qty:float|string,estimated_unit_cost:float|string}>  $items
     * @return array<int,array<string,mixed>>
     */
    private function buildLines(array $items): array
    {
        $itemIds = array_column($items, 'item_id');
        $units = Item::whereIn('id', $itemIds)->pluck('base_unit_id', 'id');

        return array_map(function (array $row) use ($units) {
            $itemId = (int) $row['item_id'];
            $qty = (float) $row['ordered_qty'];
            $cost = (float) $row['estimated_unit_cost'];

            if (! isset($units[$itemId])) {
                throw new InventoryException("Inventory item {$itemId} does not exist.");
            }

            return [
                'item_id' => $itemId,
                'unit_id' => $units[$itemId],
                'ordered_qty' => $qty,
                'received_qty' => 0,
                'estimated_unit_cost' => $cost,
                'line_total' => round($qty * $cost, 4),
            ];
        }, $items);
    }

    /**
     * @param  array<int,array<string,mixed>>  $lines
     */
    private function sumLines(array $lines): float
    {
        return round(array_sum(array_column($lines, 'line_total')), 4);
    }
}
