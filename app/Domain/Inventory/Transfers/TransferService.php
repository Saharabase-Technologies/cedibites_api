<?php

namespace App\Domain\Inventory\Transfers;

use App\Domain\Inventory\Batches\BatchService;
use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Domain\Inventory\Support\ReferenceGenerator;
use App\Enums\Inventory\RequisitionStatus;
use App\Enums\Inventory\TransferStatus;
use App\Models\Inventory\Batch;
use App\Models\Inventory\Item;
use App\Models\Inventory\Requisition;
use App\Models\Inventory\Transfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stock transfer lifecycle + ledger posting. Stock leaves the source at `sent`
 * (transfer_out, FEFO-allocated) and arrives at the destination at `received`
 * (transfer_in, batches rebuilt from the send snapshot so cost + expiry carry
 * across). A short receipt routes to `disputed`; the original is immutable and
 * reconciled by a corrective transfer.
 */
class TransferService
{
    public function __construct(
        private readonly MovementPostingEngine $posting,
        private readonly BatchService $batches,
        private readonly ReferenceGenerator $references,
    ) {}

    /**
     * @param  array{source_location_id:int,destination_location_id:int,notes?:string|null,items:array<int,array{item_id:int,requested_qty:float}>}  $data
     */
    public function create(array $data, User $actor): Transfer
    {
        if ((int) $data['source_location_id'] === (int) $data['destination_location_id']) {
            throw new InventoryException('Source and destination must be different locations.');
        }

        return DB::transaction(function () use ($data, $actor) {
            $transfer = Transfer::create([
                'reference' => $this->references->transfer(),
                'source_location_id' => $data['source_location_id'],
                'destination_location_id' => $data['destination_location_id'],
                'status' => TransferStatus::Draft,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            $transfer->lines()->createMany($this->buildLines($data['items']));

            return $transfer;
        });
    }

    /** Replace a draft transfer's lines + meta. Only drafts are editable. */
    public function update(Transfer $transfer, array $data): Transfer
    {
        $this->assertEditable($transfer);

        return DB::transaction(function () use ($transfer, $data) {
            if (array_key_exists('items', $data)) {
                $transfer->lines()->delete();
                $transfer->lines()->createMany($this->buildLines($data['items']));
            }
            if (array_key_exists('notes', $data)) {
                $transfer->notes = $data['notes'];
            }
            $transfer->save();

            return $transfer;
        });
    }

    /**
     * draft → submitted. Validates the source holds enough of every item.
     * A deficit blocks unless $override is exercised (admin-gated at the route),
     * recorded in source_validation_overridden_by.
     */
    public function submit(Transfer $transfer, User $actor, bool $override = false): Transfer
    {
        $this->assertStatus($transfer, TransferStatus::Draft, 'submitted');

        return DB::transaction(function () use ($transfer, $actor, $override) {
            $deficits = $this->sourceDeficits($transfer);
            if ($deficits !== [] && ! $override) {
                throw new InventoryException('Source stock is short for: '.implode('; ', $deficits).'.');
            }

            $transfer->status = TransferStatus::Submitted;
            $transfer->submitted_at = now();
            if ($deficits !== [] && $override) {
                $transfer->source_validation_overridden_by = $actor->id;
            }
            $transfer->save();

            return $transfer;
        });
    }

    /** submitted → approved. */
    public function approve(Transfer $transfer, User $actor): Transfer
    {
        $this->assertStatus($transfer, TransferStatus::Submitted, 'approved');

        $transfer->status = TransferStatus::Approved;
        $transfer->approved_by = $actor->id;
        $transfer->approved_at = now();
        $transfer->save();

        return $transfer;
    }

    /**
     * approved → sent. Deducts the source (transfer_out, FEFO) and snapshots the
     * allocation per line so the destination can rebuild batches on receipt.
     *
     * @param  array<int,float>  $sentQty  optional line_id => qty override (defaults to requested)
     */
    public function send(Transfer $transfer, User $actor, array $sentQty = []): Transfer
    {
        if (! $transfer->status->canSend()) {
            throw new InventoryException("Only approved transfers can be sent (current status: {$transfer->status->value}).");
        }

        return DB::transaction(function () use ($transfer, $actor, $sentQty) {
            $sourceId = $transfer->source_location_id;

            foreach ($transfer->lines as $line) {
                $qty = round((float) ($sentQty[$line->id] ?? $line->requested_qty), 4);
                if ($qty <= 0) {
                    throw new InventoryException('Sent quantity must be greater than zero.');
                }

                $available = $this->onHand((int) $line->item_id, $sourceId);
                if ($qty > $available) {
                    $name = Item::whereKey($line->item_id)->value('name') ?? "item {$line->item_id}";
                    throw new InventoryException("Not enough stock of {$name}: {$available} on hand, sending {$qty}.");
                }

                $snapshot = [];
                $lineCost = 0.0;
                $i = 0;
                foreach ($this->batches->allocate((int) $line->item_id, $sourceId, $qty) as $alloc) {
                    $cost = $alloc['unit_cost'] ?? $this->weightedCost((int) $line->item_id, $sourceId);
                    $expiry = $alloc['batch_id'] ? Batch::whereKey($alloc['batch_id'])->value('expiry_date') : null;

                    $this->posting->post([
                        'item_id' => $line->item_id,
                        'location_id' => $sourceId,
                        'quantity' => -1 * $alloc['qty'],
                        'movement_type' => 'transfer_out',
                        'reference_type' => 'inventory_transfer',
                        'reference_id' => $transfer->id,
                        'batch_id' => $alloc['batch_id'],
                        'unit_cost_at_time' => $cost,
                        'user_id' => $actor->id,
                        'idempotency_key' => "transfer_out:{$transfer->id}:line:{$line->id}:i:{$i}",
                        'occurred_at' => now(),
                    ]);

                    $snapshot[] = [
                        'batch_id' => $alloc['batch_id'],
                        'qty' => $alloc['qty'],
                        'unit_cost' => $cost,
                        'expiry_date' => $expiry ? (string) $expiry : null,
                    ];
                    $lineCost += $alloc['qty'] * (float) $cost;
                    $i++;
                }

                $line->sent_qty = $qty;
                $line->unit_cost_at_time = $qty > 0 ? round($lineCost / $qty, 4) : 0;
                $line->sent_allocations = $snapshot;
                $line->save();
            }

            $transfer->status = TransferStatus::Sent;
            $transfer->sent_by = $actor->id;
            $transfer->sent_at = now();
            $transfer->save();

            return $transfer;
        });
    }

    /**
     * sent → received (clean) or disputed (any line short). Adds the received qty
     * to the destination (transfer_in), rebuilding batches from the send snapshot
     * so cost + expiry carry across.
     *
     * @param  array<int,float>  $receivedQty  line_id => qty (defaults to sent_qty)
     */
    public function receive(Transfer $transfer, User $actor, array $receivedQty = [], ?string $disputeReason = null): Transfer
    {
        if (! $transfer->status->canReceive()) {
            throw new InventoryException("Only sent transfers can be received (current status: {$transfer->status->value}).");
        }

        return DB::transaction(function () use ($transfer, $actor, $receivedQty, $disputeReason) {
            $destId = $transfer->destination_location_id;
            $totalDiscrepancy = 0.0;

            foreach ($transfer->lines as $line) {
                $sent = (float) $line->sent_qty;
                $qty = round((float) ($receivedQty[$line->id] ?? $sent), 4);
                if ($qty < 0) {
                    throw new InventoryException('Received quantity cannot be negative.');
                }
                if ($qty > $sent) {
                    throw new InventoryException('Received quantity cannot exceed what was sent.');
                }

                $tracked = (bool) Item::whereKey($line->item_id)->value('expiry_tracked');
                $outstanding = $qty;
                $i = 0;
                foreach (($line->sent_allocations ?? []) as $alloc) {
                    if ($outstanding <= 0) {
                        break;
                    }
                    $take = round(min((float) $alloc['qty'], $outstanding), 4);
                    if ($take <= 0) {
                        continue;
                    }
                    $cost = (float) ($alloc['unit_cost'] ?? $line->unit_cost_at_time);

                    $destBatchId = null;
                    if ($tracked) {
                        $destBatchId = Batch::create([
                            'item_id' => $line->item_id,
                            'location_id' => $destId,
                            'purchase_item_id' => null,
                            'received_qty' => $take,
                            'remaining_qty' => $take,
                            'unit_cost' => $cost,
                            'expiry_date' => $alloc['expiry_date'] ?? null,
                            'received_at' => now(),
                        ])->id;
                    }

                    $this->posting->post([
                        'item_id' => $line->item_id,
                        'location_id' => $destId,
                        'quantity' => $take,
                        'movement_type' => 'transfer_in',
                        'reference_type' => 'inventory_transfer',
                        'reference_id' => $transfer->id,
                        'batch_id' => $destBatchId,
                        'unit_cost_at_time' => $cost,
                        'user_id' => $actor->id,
                        'idempotency_key' => "transfer_in:{$transfer->id}:line:{$line->id}:i:{$i}",
                        'occurred_at' => now(),
                    ]);

                    $outstanding = round($outstanding - $take, 4);
                    $i++;
                }

                $line->received_qty = $qty;
                $line->save();
                $totalDiscrepancy += round($sent - $qty, 4);
            }

            $transfer->received_by = $actor->id;
            $transfer->received_at = now();

            if ($totalDiscrepancy > 0) {
                $transfer->status = TransferStatus::Disputed;
                $transfer->save();
                $transfer->dispute()->create([
                    'status' => 'open',
                    'raised_by' => $actor->id,
                    'reason' => $disputeReason ?: 'Short receipt — received less than was sent.',
                    'discrepancy_qty' => round($totalDiscrepancy, 4),
                ]);
            } else {
                $transfer->status = TransferStatus::Received;
                $transfer->save();
                $this->fulfilRequisition($transfer);
            }

            return $transfer;
        });
    }

    /**
     * Flip a linked requisition to `fulfilled` once its transfer is received in
     * full. The single coupling point between the transfer and requisition flows.
     */
    private function fulfilRequisition(Transfer $transfer): void
    {
        if (! $transfer->requisition_id) {
            return;
        }

        $requisition = Requisition::whereKey($transfer->requisition_id)
            ->where('status', RequisitionStatus::Approved->value)
            ->first();

        $requisition?->update([
            'status' => RequisitionStatus::Fulfilled,
            'fulfilled_at' => now(),
        ]);
    }

    /**
     * disputed → closed_disputed. Spawns a corrective draft transfer for the
     * shortfall (linked via parent_transfer_id) and resolves the dispute. The
     * original is never edited.
     */
    public function resolveDispute(Transfer $transfer, User $actor, ?string $notes = null): Transfer
    {
        if ($transfer->status !== TransferStatus::Disputed) {
            throw new InventoryException("Only disputed transfers can be resolved (current status: {$transfer->status->value}).");
        }

        return DB::transaction(function () use ($transfer, $actor, $notes) {
            $shortfallLines = [];
            foreach ($transfer->lines as $line) {
                $short = round((float) $line->sent_qty - (float) $line->received_qty, 4);
                if ($short > 0) {
                    $shortfallLines[] = ['item_id' => (int) $line->item_id, 'requested_qty' => $short];
                }
            }

            $corrective = null;
            if ($shortfallLines !== []) {
                $corrective = Transfer::create([
                    'reference' => $this->references->transfer(),
                    'source_location_id' => $transfer->source_location_id,
                    'destination_location_id' => $transfer->destination_location_id,
                    'status' => TransferStatus::Draft,
                    'parent_transfer_id' => $transfer->id,
                    // Carry the requisition link so fulfilment still tracks once the
                    // corrective transfer is received.
                    'requisition_id' => $transfer->requisition_id,
                    'notes' => 'Corrective transfer for disputed '.$transfer->reference,
                    'created_by' => $actor->id,
                ]);
                $corrective->lines()->createMany($this->buildLines($shortfallLines));
            }

            $dispute = $transfer->dispute()->where('status', 'open')->first();
            if ($dispute) {
                $dispute->update([
                    'status' => 'resolved',
                    'corrective_transfer_id' => $corrective?->id,
                    'resolved_by' => $actor->id,
                    'resolved_at' => now(),
                    'resolution_notes' => $notes,
                ]);
            }

            $transfer->status = TransferStatus::ClosedDisputed;
            $transfer->save();

            return $transfer;
        });
    }

    public function cancel(Transfer $transfer, User $actor, string $reason): Transfer
    {
        if (! $transfer->status->isCancellable()) {
            throw new InventoryException("This transfer can no longer be cancelled (current status: {$transfer->status->value}).");
        }

        $transfer->status = TransferStatus::Cancelled;
        $transfer->cancelled_by = $actor->id;
        $transfer->cancelled_at = now();
        $transfer->cancel_reason = $reason;
        $transfer->save();

        return $transfer;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

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

    /** @return array<int,string> human-readable deficit messages, empty if all covered */
    private function sourceDeficits(Transfer $transfer): array
    {
        $deficits = [];
        // Aggregate demand per item (a transfer can list an item on multiple lines).
        $demand = [];
        foreach ($transfer->lines as $line) {
            $demand[(int) $line->item_id] = ($demand[(int) $line->item_id] ?? 0) + (float) $line->requested_qty;
        }
        foreach ($demand as $itemId => $needed) {
            $available = $this->onHand($itemId, $transfer->source_location_id);
            if (round($needed, 4) > $available) {
                $name = Item::whereKey($itemId)->value('name') ?? "item {$itemId}";
                $deficits[] = "{$name} (need {$needed}, have {$available})";
            }
        }

        return $deficits;
    }

    private function onHand(int $itemId, int $locationId): float
    {
        $qty = DB::table('inventory_stock_balances')
            ->where('item_id', $itemId)->where('location_id', $locationId)->value('quantity');

        return $qty !== null ? (float) $qty : 0.0;
    }

    private function weightedCost(int $itemId, int $locationId): ?float
    {
        $cost = DB::table('inventory_stock_balances')
            ->where('item_id', $itemId)->where('location_id', $locationId)->value('weighted_avg_cost');

        return $cost !== null ? (float) $cost : null;
    }

    private function assertEditable(Transfer $transfer): void
    {
        if (! $transfer->status->isEditable()) {
            throw new InventoryException("Only draft transfers can be edited (current status: {$transfer->status->value}).");
        }
    }

    private function assertStatus(Transfer $transfer, TransferStatus $expected, string $action): void
    {
        if ($transfer->status !== $expected) {
            throw new InventoryException("Transfer cannot be {$action} from status {$transfer->status->value}.");
        }
    }
}
