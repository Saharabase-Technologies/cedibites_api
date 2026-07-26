<?php

namespace App\Domain\Inventory\Transfers;

use App\Domain\Inventory\Batches\BatchService;
use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Domain\Inventory\Support\ReferenceGenerator;
use App\Domain\Inventory\Wastage\WastageService;
use App\Enums\Inventory\RequisitionStatus;
use App\Enums\Inventory\TransferStatus;
use App\Enums\Inventory\WastageReason;
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
        $this->assertOperatesAt($actor, (int) $transfer->source_location_id, $transfer->sourceLocation?->name, 'submit');

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
        $this->assertOperatesAt($actor, (int) $transfer->source_location_id, $transfer->sourceLocation?->name, 'approve');

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

        // Dispatching is the SOURCE's act. The destination watches it arrive —
        // a branch manager receiving from the mother kitchen has no business
        // declaring that the mother kitchen shipped.
        $this->assertOperatesAt($actor, (int) $transfer->source_location_id, $transfer->sourceLocation?->name, 'send');

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
    /**
     * sent → received / rejected / disputed.
     *
     * Three things can happen to each line, and the system has to tell them
     * apart because they have different owners:
     *
     *   accepted — added to the destination, and now the destination's to answer
     *              for. Anything wrong with it from here is their wastage.
     *   refused  — it turned up and it is going back on the lorry. Returned to
     *              the source immediately and raised as a wastage claim there,
     *              because the sender never stopped owning it.
     *   missing  — it never turned up. Only this is a dispute; only this is
     *              something the two ends actually disagree about.
     *
     * Refusing every line is the door being shut on the whole consignment, and
     * lands the transfer in `rejected`.
     *
     * @param  array<int,float>  $receivedQty  line_id => accepted qty (defaults to everything sent)
     * @param  array<int,array{qty:float, reason?:string|null, note?:string|null}>  $refusals  line_id => refusal
     */
    public function receive(
        Transfer $transfer,
        User $actor,
        array $receivedQty = [],
        ?string $disputeReason = null,
        array $refusals = [],
    ): Transfer {
        if (! $transfer->status->canReceive()) {
            throw new InventoryException("Only sent transfers can be received (current status: {$transfer->status->value}).");
        }

        // Separation of duties: whoever dispatched the stock cannot also sign for
        // its arrival. A short or wrong delivery is only caught if the receiving
        // end confirms it, and the sender confirming their own consignment makes
        // the dispute path unreachable. Anyone else at the destination — branch
        // manager or admin — can receive it.
        if ($transfer->sent_by !== null && (int) $transfer->sent_by === (int) $actor->id) {
            throw new InventoryException(
                'You sent this transfer, so you cannot also receive it. Someone at the destination must confirm it arrived.'
            );
        }

        // Each end accounts for its own side. Overseeing every location is not
        // the same as working at one: a warehouse manager fulfilling a branch's
        // requisition dispatches from the mother kitchen, and the branch signs
        // for what arrives. Admins belong to no kitchen and may act at either end.
        $operating = $actor->operatingLocationIds();
        if ($operating !== null
            && ! in_array((int) $transfer->destination_location_id, array_map('intval', $operating), true)) {
            $where = $transfer->destinationLocation?->name ?? 'its destination';
            throw new InventoryException(
                "This transfer is going to {$where}. Only someone there can confirm it arrived."
            );
        }

        return DB::transaction(function () use ($transfer, $actor, $receivedQty, $disputeReason, $refusals) {
            $destId = (int) $transfer->destination_location_id;
            $sourceId = (int) $transfer->source_location_id;
            $totalMissing = 0.0;
            $totalRefused = 0.0;
            $refusedLines = [];
            $refuseReason = null;
            $refuseNote = null;

            foreach ($transfer->lines as $line) {
                $sent = (float) $line->sent_qty;
                $qty = round((float) ($receivedQty[$line->id] ?? $sent), 4);

                $refusal = $refusals[$line->id] ?? null;
                $refused = round((float) ($refusal['qty'] ?? 0), 4);

                if ($qty < 0 || $refused < 0) {
                    throw new InventoryException('Quantities cannot be negative.');
                }
                if (round($qty + $refused, 4) > $sent) {
                    $name = Item::whereKey($line->item_id)->value('name') ?? "item {$line->item_id}";
                    throw new InventoryException(
                        "More {$name} accounted for than was sent: {$sent} sent, {$qty} accepted plus {$refused} refused."
                    );
                }

                $reason = null;
                if ($refused > 0) {
                    $reason = WastageReason::tryFrom((string) ($refusal['reason'] ?? ''));
                    if ($reason === null) {
                        $name = Item::whereKey($line->item_id)->value('name') ?? "item {$line->item_id}";
                        throw new InventoryException("Say what is wrong with the {$name} you are sending back.");
                    }
                    $note = trim((string) ($refusal['note'] ?? ''));
                    if ($reason->requiresNote() && $note === '') {
                        throw new InventoryException('Choosing “Other” means saying what happened — add a note.');
                    }
                    $refuseReason ??= $reason;
                    $refuseNote ??= ($note !== '' ? $note : null);
                }

                $tracked = (bool) Item::whereKey($line->item_id)->value('expiry_tracked');

                // Walk the send allocation once, filling the accepted quantity
                // first and the refused quantity from what is left. Each parcel
                // keeps the cost and expiry it was sent with, whichever way it
                // ends up going.
                $toAccept = $qty;
                $toRefuse = $refused;
                $i = 0;
                foreach (($line->sent_allocations ?? []) as $alloc) {
                    $available = (float) $alloc['qty'];
                    $cost = (float) ($alloc['unit_cost'] ?? $line->unit_cost_at_time);

                    if ($toAccept > 0 && $available > 0) {
                        $take = round(min($available, $toAccept), 4);
                        $this->landStock($transfer, $line, $destId, $take, $cost, $alloc['expiry_date'] ?? null, $tracked, $actor, "transfer_in:{$transfer->id}:line:{$line->id}:i:{$i}");
                        $toAccept = round($toAccept - $take, 4);
                        $available = round($available - $take, 4);
                        $i++;
                    }

                    if ($toRefuse > 0 && $available > 0) {
                        $give = round(min($available, $toRefuse), 4);
                        // Straight back to where it came from. The source's
                        // ledger is made whole; whether the goods are then
                        // binned is the source's own wastage decision.
                        $this->landStock($transfer, $line, $sourceId, $give, $cost, $alloc['expiry_date'] ?? null, $tracked, $actor, "transfer_return:{$transfer->id}:line:{$line->id}:i:{$i}");
                        $toRefuse = round($toRefuse - $give, 4);
                        $i++;
                    }

                    if ($toAccept <= 0 && $toRefuse <= 0) {
                        break;
                    }
                }

                $line->received_qty = $qty;
                $line->refused_qty = $refused > 0 ? $refused : null;
                $line->refuse_reason = $reason?->value;
                $line->refuse_note = $refused > 0 ? ($refusal['note'] ?? null) : null;
                $line->save();

                if ($refused > 0) {
                    $refusedLines[] = [
                        'item_id' => (int) $line->item_id,
                        'unit_id' => (int) $line->unit_id,
                        'quantity' => $refused,
                    ];
                }

                $totalRefused += $refused;
                $totalMissing += round($sent - $qty - $refused, 4);
            }

            $transfer->received_by = $actor->id;
            $transfer->received_at = now();

            // Refused goods go back to the sender's shelf and land in the
            // sender's queue: they decide whether to write them off. Raised
            // before the status is settled so the claim exists even when the
            // same delivery also has something missing.
            if ($refusedLines !== []) {
                app(WastageService::class)->raiseFromDeliveryRejection(
                    $transfer,
                    $refusedLines,
                    $refuseReason ?? WastageReason::DamagedInTransit,
                    $refuseNote,
                    $actor,
                );
            }

            $everythingRefused = $totalRefused > 0 && $totalMissing <= 0
                && $transfer->lines->every(fn ($l) => (float) $l->received_qty === 0.0);

            if ($totalMissing > 0) {
                // Only a genuine disagreement — stock that neither end can
                // account for — is a dispute.
                $transfer->status = TransferStatus::Disputed;
                $transfer->save();
                $transfer->dispute()->create([
                    'status' => 'open',
                    'raised_by' => $actor->id,
                    'reason' => $disputeReason ?: 'Short receipt — less arrived than was sent.',
                    'discrepancy_qty' => round($totalMissing, 4),
                ]);
            } elseif ($everythingRefused) {
                $transfer->status = TransferStatus::Rejected;
                $transfer->rejected_by = $actor->id;
                $transfer->rejected_at = now();
                $transfer->reject_reason = $disputeReason ?: $refuseNote;
                $transfer->reject_reason_code = $refuseReason?->value;
                $transfer->save();
            } else {
                $transfer->status = TransferStatus::Received;
                $transfer->save();
                // A part-refused delivery has not met the request, so the
                // requisition behind it stays open for a corrective run.
                if ($totalRefused <= 0) {
                    $this->fulfilRequisition($transfer);
                }
            }

            // The return leg of a wastage claim arriving back at the warehouse
            // puts the goods in front of the manager who has to sign for them.
            if ($transfer->wastage_id !== null && $transfer->status === TransferStatus::Received) {
                app(WastageService::class)->onReturnReceived($transfer);
            }

            return $transfer;
        });
    }

    /**
     * Put stock on a shelf: rebuild the batch from the send snapshot so cost and
     * expiry survive the journey, then post the movement. Used for both legs —
     * goods accepted at the destination and goods refused straight back to the
     * source — because physically they are the same act.
     */
    private function landStock(
        Transfer $transfer,
        $line,
        int $locationId,
        float $qty,
        float $cost,
        ?string $expiryDate,
        bool $tracked,
        User $actor,
        string $idempotencyKey,
    ): void {
        if ($qty <= 0) {
            return;
        }

        $batchId = null;
        if ($tracked) {
            $batchId = Batch::create([
                'item_id' => $line->item_id,
                'location_id' => $locationId,
                'purchase_item_id' => null,
                'received_qty' => $qty,
                'remaining_qty' => $qty,
                'unit_cost' => $cost,
                'expiry_date' => $expiryDate,
                'received_at' => now(),
            ])->id;
        }

        $this->posting->post([
            'item_id' => $line->item_id,
            'location_id' => $locationId,
            'quantity' => $qty,
            'movement_type' => 'transfer_in',
            'reference_type' => 'inventory_transfer',
            'reference_id' => $transfer->id,
            'batch_id' => $batchId,
            'unit_cost_at_time' => $cost,
            'user_id' => $actor->id,
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => now(),
        ]);
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
    /**
     * @param  bool  $sendCorrective  true spawns a corrective transfer for the
     *                                shortfall; false accepts it as a loss. The
     *                                ledger is identical either way — the stock
     *                                left the source and never arrived — so this
     *                                only records which decision was taken.
     */
    public function resolveDispute(
        Transfer $transfer,
        User $actor,
        ?string $notes = null,
        bool $sendCorrective = true,
    ): Transfer {
        if ($transfer->status !== TransferStatus::Disputed) {
            throw new InventoryException("Only disputed transfers can be resolved (current status: {$transfer->status->value}).");
        }

        return DB::transaction(function () use ($transfer, $actor, $notes, $sendCorrective) {
            $shortfallLines = [];
            $writeOffLines = [];
            $shortfallQty = 0.0;
            foreach ($transfer->lines as $line) {
                // Refused goods are not missing — they went back to the source
                // and were accounted for there. Only what nobody can find is a
                // shortfall.
                $short = round(
                    (float) $line->sent_qty - (float) $line->received_qty - (float) ($line->refused_qty ?? 0),
                    4,
                );
                if ($short > 0) {
                    $shortfallLines[] = ['item_id' => (int) $line->item_id, 'requested_qty' => $short];
                    $writeOffLines[] = [
                        'item_id' => (int) $line->item_id,
                        'unit_id' => (int) $line->unit_id,
                        'quantity' => $short,
                    ];
                    $shortfallQty += $short;
                }
            }

            $corrective = null;
            if ($shortfallLines !== [] && $sendCorrective) {
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

            // Deciding to stop chasing the shortfall is a decision to absorb it.
            // The ledger already recorded the loss at the short receipt — the
            // stock left the source and never arrived — so this classifies it
            // into the wastage report without moving anything. Attributed to the
            // source, which is the leg it went missing on.
            $writeOff = null;
            if ($writeOffLines !== [] && ! $sendCorrective) {
                $writeOff = app(WastageService::class)->classifyTransferShortfall(
                    $transfer,
                    $writeOffLines,
                    $actor,
                    $notes,
                );
            }

            $dispute = $transfer->dispute()->where('status', 'open')->first();
            if ($dispute) {
                $dispute->update([
                    'status' => 'resolved',
                    // Written off only when there was a real shortfall to write
                    // off — a dispute raised over something other than quantity
                    // resolves as neither.
                    'resolution' => match (true) {
                        $corrective !== null => 'corrective',
                        $shortfallQty > 0 => 'written_off',
                        default => null,
                    },
                    'written_off_qty' => $corrective === null ? round($shortfallQty, 4) : 0,
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

    /**
     * The whole corrective chain a transfer belongs to, oldest first.
     *
     * A short delivery spawns a corrective transfer, which can itself be
     * received short and spawn another. Each row only knows its immediate
     * parent, so the detail screen could show one hop in each direction and no
     * more — you could not see, from the middle of a chain, what originally went
     * wrong or how it finally ended.
     *
     * Walks up to the root, then down through every descendant.
     *
     * @return array<int,array{id:int,reference:string,status:string,parent_transfer_id:int|null,depth:int,is_current:bool}>
     */
    public function lineage(Transfer $transfer): array
    {
        // Up to the root. Bounded in case a bad parent link ever forms a cycle.
        $root = $transfer;
        $guard = 0;
        while ($root->parent_transfer_id !== null && $guard++ < 50) {
            $parent = Transfer::find($root->parent_transfer_id);
            if (! $parent) {
                break;
            }
            $root = $parent;
        }

        $chain = [];
        $walk = function (Transfer $node, int $depth) use (&$walk, &$chain, $transfer): void {
            $chain[] = [
                'id' => $node->id,
                'reference' => $node->reference,
                'status' => $node->status->value,
                'parent_transfer_id' => $node->parent_transfer_id,
                'depth' => $depth,
                'is_current' => $node->id === $transfer->id,
            ];

            Transfer::where('parent_transfer_id', $node->id)
                ->orderBy('id')
                ->get()
                ->each(fn (Transfer $child) => $walk($child, $depth + 1));
        };
        $walk($root, 0);

        return $chain;
    }

    /**
     * Can a location cover this demand right now?
     *
     * Public so a form can ask before anything is committed — the answer to
     * "does the mother kitchen actually have this?" should be visible while the
     * request is being written, not sprung at submit time.
     *
     * @param  array<int,array{item_id:int|string,qty:float|string}>  $items
     * @return array<int,array{item_id:int,name:string,required:float,available:float,sufficient:bool,shortfall:float}>
     */
    public function checkAvailability(int $locationId, array $items): array
    {
        // Aggregate demand per item — the same item may appear on several lines.
        $demand = [];
        foreach ($items as $row) {
            $id = (int) $row['item_id'];
            $demand[$id] = ($demand[$id] ?? 0) + (float) $row['qty'];
        }

        $names = Item::whereIn('id', array_keys($demand))->pluck('name', 'id');

        $out = [];
        foreach ($demand as $itemId => $needed) {
            $needed = round($needed, 4);
            $available = $this->onHand($itemId, $locationId);
            $out[] = [
                'item_id' => $itemId,
                'name' => $names[$itemId] ?? "item {$itemId}",
                'required' => $needed,
                'available' => $available,
                'sufficient' => $needed <= $available,
                'shortfall' => $needed > $available ? round($needed - $available, 4) : 0.0,
            ];
        }

        return $out;
    }

    /** @return array<int,string> human-readable deficit messages, empty if all covered */
    private function sourceDeficits(Transfer $transfer): array
    {
        $items = $transfer->lines
            ->map(fn ($line) => ['item_id' => (int) $line->item_id, 'qty' => (float) $line->requested_qty])
            ->all();

        return collect($this->checkAvailability((int) $transfer->source_location_id, $items))
            ->reject(fn (array $row) => $row['sufficient'])
            ->map(fn (array $row) => "{$row['name']} (need {$row['required']}, have {$row['available']})")
            ->values()
            ->all();
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

    /**
     * Refuse an action at a location the actor does not work at.
     *
     * Outbound actions (submit, approve, send, cancel) belong to the source;
     * receiving belongs to the destination. Admins operate anywhere.
     */
    private function assertOperatesAt(User $actor, int $locationId, ?string $locationName, string $action): void
    {
        $operating = $actor->operatingLocationIds();

        if ($operating === null || in_array($locationId, array_map('intval', $operating), true)) {
            return;
        }

        $where = $locationName ?? 'that location';
        throw new InventoryException(
            "This transfer is dispatched from {$where}, so only someone there can {$action} it."
        );
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
