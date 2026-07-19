<?php

namespace App\Domain\Inventory\Requisitions;

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Support\ReferenceGenerator;
use App\Domain\Inventory\Transfers\TransferService;
use App\Enums\Inventory\RequisitionStatus;
use App\Models\Inventory\Item;
use App\Models\Inventory\Requisition;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Requisition lifecycle — the "request layer" in front of a physical transfer.
 * A branch raises a multi-line request against the warehouse; on approval the
 * warehouse manager sets the granted quantities and a fulfilling transfer is
 * spawned (draft) via the TransferService. The requisition flips to `fulfilled`
 * automatically when that transfer is received (see TransferService::receive()).
 */
class RequisitionService
{
    public function __construct(
        private readonly ReferenceGenerator $references,
        private readonly TransferService $transfers,
    ) {}

    /**
     * @param  array{requesting_location_id:int,source_location_id:int,purpose?:string,notes?:string|null,items:array<int,array{item_id:int,requested_qty:float}>}  $data
     */
    public function create(array $data, User $actor): Requisition
    {
        if ((int) $data['requesting_location_id'] === (int) ($data['source_location_id'] ?? 0)) {
            throw new InventoryException('The requesting and source locations must be different.');
        }

        return DB::transaction(function () use ($data, $actor) {
            $requisition = Requisition::create([
                'reference' => $this->references->requisition(),
                'requesting_location_id' => $data['requesting_location_id'],
                'source_type' => 'warehouse',
                'source_location_id' => $data['source_location_id'],
                'purpose' => $data['purpose'] ?? 'supplementary',
                'status' => RequisitionStatus::Draft,
                'notes' => $data['notes'] ?? null,
                'requested_by' => $actor->id,
            ]);

            $requisition->lines()->createMany($this->buildLines($data['items']));

            return $requisition;
        });
    }

    /** Replace a draft requisition's lines + meta. Only drafts are editable. */
    public function update(Requisition $requisition, array $data): Requisition
    {
        if (! $requisition->status->isEditable()) {
            throw new InventoryException("Only draft requisitions can be edited (current status: {$requisition->status->value}).");
        }

        return DB::transaction(function () use ($requisition, $data) {
            if (array_key_exists('items', $data)) {
                $requisition->lines()->delete();
                $requisition->lines()->createMany($this->buildLines($data['items']));
            }
            foreach (['purpose', 'notes', 'source_location_id'] as $field) {
                if (array_key_exists($field, $data)) {
                    $requisition->{$field} = $data[$field];
                }
            }
            $requisition->save();

            return $requisition;
        });
    }

    /** draft → submitted. */
    public function submit(Requisition $requisition, User $actor): Requisition
    {
        $this->assertStatus($requisition, RequisitionStatus::Draft, 'submitted');

        $requisition->status = RequisitionStatus::Submitted;
        $requisition->submitted_at = now();
        $requisition->save();

        return $requisition;
    }

    /**
     * submitted → approved. The warehouse manager grants a quantity per line
     * (defaults to the requested amount, may be trimmed to 0 to skip a line) and
     * a draft transfer is spawned for everything granted.
     *
     * @param  array<int,float>  $approvedQty  line_id => granted qty
     */
    public function approve(Requisition $requisition, User $actor, array $approvedQty = []): Requisition
    {
        $this->assertStatus($requisition, RequisitionStatus::Submitted, 'approved');

        if (! $requisition->source_location_id) {
            throw new InventoryException('A source location is required before a requisition can be approved.');
        }

        return DB::transaction(function () use ($requisition, $actor, $approvedQty) {
            $transferItems = [];
            foreach ($requisition->lines as $line) {
                $granted = round((float) ($approvedQty[$line->id] ?? $line->requested_qty), 4);
                if ($granted < 0) {
                    throw new InventoryException('Approved quantity cannot be negative.');
                }
                $line->approved_qty = $granted;
                $line->save();

                if ($granted > 0) {
                    $transferItems[] = ['item_id' => (int) $line->item_id, 'requested_qty' => $granted];
                }
            }

            if ($transferItems === []) {
                throw new InventoryException('Approve at least one line with a quantity greater than zero, or reject the requisition.');
            }

            // Spawn the fulfilling transfer (warehouse → requesting branch).
            $transfer = $this->transfers->create([
                'source_location_id' => (int) $requisition->source_location_id,
                'destination_location_id' => (int) $requisition->requesting_location_id,
                'notes' => "Fulfils requisition {$requisition->reference}",
                'items' => $transferItems,
            ], $actor);
            $transfer->requisition_id = $requisition->id;
            $transfer->save();

            $requisition->status = RequisitionStatus::Approved;
            $requisition->approved_by = $actor->id;
            $requisition->approved_at = now();
            $requisition->fulfilling_transfer_id = $transfer->id;
            $requisition->save();

            return $requisition;
        });
    }

    /** submitted → rejected. */
    public function reject(Requisition $requisition, User $actor, string $reason): Requisition
    {
        $this->assertStatus($requisition, RequisitionStatus::Submitted, 'rejected');

        $requisition->status = RequisitionStatus::Rejected;
        $requisition->approved_by = $actor->id;
        $requisition->rejected_at = now();
        $requisition->rejection_reason = $reason;
        $requisition->save();

        return $requisition;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * @param  array<int,array{item_id:int,requested_qty:float}>  $items
     * @return array<int,array<string,mixed>>
     */
    private function buildLines(array $items): array
    {
        $itemIds = array_column($items, 'item_id');
        $units = Item::whereIn('id', $itemIds)->pluck('base_unit_id', 'id');

        return array_map(function (array $row) use ($units) {
            $itemId = (int) $row['item_id'];
            if (! isset($units[$itemId])) {
                throw new InventoryException("Inventory item {$itemId} does not exist.");
            }
            $qty = round((float) $row['requested_qty'], 4);
            if ($qty <= 0) {
                throw new InventoryException('Requested quantity must be greater than zero.');
            }

            return [
                'item_id' => $itemId,
                'unit_id' => $units[$itemId],
                'requested_qty' => $qty,
            ];
        }, $items);
    }

    private function assertStatus(Requisition $requisition, RequisitionStatus $expected, string $action): void
    {
        if ($requisition->status !== $expected) {
            throw new InventoryException("Requisition cannot be {$action} from status {$requisition->status->value}.");
        }
    }
}
