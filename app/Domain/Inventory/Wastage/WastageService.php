<?php

namespace App\Domain\Inventory\Wastage;

use App\Domain\Inventory\Batches\BatchService;
use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Domain\Inventory\Support\ReferenceGenerator;
use App\Enums\Inventory\TransferStatus;
use App\Enums\Inventory\WastageOrigin;
use App\Enums\Inventory\WastageReason;
use App\Enums\Inventory\WastageStatus;
use App\Http\Controllers\Api\Inventory\InventorySettingController;
use App\Models\Inventory\Alert;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\Inventory\Transfer;
use App\Models\Inventory\Wastage;
use App\Models\Inventory\WastagePhoto;
use App\Models\UploadSessionFile;
use App\Models\User;
use App\Services\Media\EvidenceImageProcessor;
use App\Services\SystemSettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Wastage - the named half of every loss.
 *
 * Stock that leaves without being sold goes out one of two doors: this one,
 * where somebody says what happened, or the variance door, where nobody knows.
 * A claim carries a reason per line, a value at weighted-average cost, and above
 * the threshold the physical journey of the goods back to the warehouse that
 * supplied them.
 *
 * TWO RULES HOLD THE WHOLE THING TOGETHER.
 *
 * 1. ONE MOVEMENT PER LOSS, EVER. A wastage either moves the stock or labels a
 *    loss the ledger already recorded - never both. A closing variance was
 *    already corrected by its count adjustment; a transfer shortfall left the
 *    source at `send` and never arrived. Those records post nothing.
 *    `WastageOrigin::postsStock()` is the single place that decides.
 *
 * 2. APPROVAL NEVER GATES THE LEDGER. Stock moves when the physical event
 *    happens; a signature decides classification and who carries the cost. That
 *    is what lets a branch close its day neutral tonight without waiting on the
 *    warehouse manager to read his messages tomorrow.
 */
class WastageService
{
    /**
     * The founder's ₵500 rule, measured - per his instruction - on the VALUE OF
     * THE GOODS BEING DECLARED WASTED, totalled across the declaration. Above it
     * a branch cannot write stock off on its own word: the goods go back to the
     * warehouse that supplied them and the warehouse manager decides.
     *
     * This is the DEFAULT. The live figure is admin-editable - read it through
     * threshold(), never this constant, or the portal's setting will appear to
     * save and change nothing.
     */
    public const DEFAULT_VALUE_THRESHOLD = 500.0;

    /**
     * The threshold in force right now.
     *
     * Static because it is read from valuation code, the reasons endpoint and
     * reconciliation alike, and none of those want a service injected for one
     * number. `SystemSettingService` caches for an hour and busts on write.
     */
    public static function threshold(): float
    {
        $value = app(SystemSettingService::class)
            ->get(InventorySettingController::THRESHOLD_KEY);

        return $value === null || ! is_numeric($value)
            ? self::DEFAULT_VALUE_THRESHOLD
            : (float) $value;
    }

    public function __construct(
        private readonly MovementPostingEngine $posting,
        private readonly BatchService $batches,
        private readonly ReferenceGenerator $references,
    ) {}

    // ── Declaring ─────────────────────────────────────────────────────────────

    /**
     * Declare a wastage by hand - the everyday case, and the only origin where
     * this record is what moves the stock.
     *
     * Under the threshold it self-approves and posts immediately. Over it, at a
     * branch, the goods must physically go back: a return transfer is raised
     * ready to send, and the write-off only posts once the warehouse manager has
     * the goods in front of him and signs. At the warehouse there is nobody above
     * the manager, so it self-approves at any value - but the admin is alerted.
     *
     * @param  array{location_id:int, notes?:string|null, lines:array<int,array{item_id:int, quantity:float, reason:string, reason_note?:string|null}>}  $data
     */
    public function record(array $data, User $actor): Wastage
    {
        $locationId = (int) $data['location_id'];
        $location = Location::findOrFail($locationId);

        $this->assertOperatesAt($actor, $locationId, $location->name);

        if (($data['lines'] ?? []) === []) {
            throw new InventoryException('A wastage must list at least one item.');
        }

        return DB::transaction(function () use ($data, $actor, $locationId, $location) {
            $lines = $this->buildLines($data['lines'], $locationId, checkOnHand: true);
            $totalValue = round(array_sum(array_column($lines, 'line_value')), 4);

            $isWarehouse = $location->type === 'warehouse';
            $overThreshold = $totalValue > self::threshold();

            // The warehouse manager answers to nobody above him for his own
            // stock; a branch over the threshold answers to him.
            $needsApproval = $overThreshold && ! $isWarehouse;
            $needsReturn = $needsApproval;

            $disposalLocationId = $needsReturn
                ? $this->resolveWarehouseFor($locationId)
                : $locationId;

            $wastage = Wastage::create([
                'reference' => $this->references->wastage(),
                'location_id' => $locationId,
                'disposal_location_id' => $disposalLocationId,
                'claimant_location_id' => $locationId,
                'origin' => WastageOrigin::Manual,
                'status' => $needsReturn
                    ? WastageStatus::PendingReturn
                    : ($needsApproval ? WastageStatus::PendingApproval : WastageStatus::Approved),
                'total_value' => $totalValue,
                'threshold_amount' => self::threshold(),
                'requires_approval' => $needsApproval,
                'requires_return' => $needsReturn,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);

            $wastage->lines()->createMany($lines);

            if ($needsReturn) {
                $this->raiseReturnTransfer($wastage, $actor);
            } elseif (! $needsApproval) {
                // Self-approved: the loss is real now, so the ledger says so now.
                $wastage->approved_by = $actor->id;
                $wastage->approved_at = now();
                $wastage->save();
                $this->postWriteOff($wastage, $actor);
            }

            if ($overThreshold) {
                $this->raiseThresholdAlert($wastage);
            }

            return $wastage->fresh(['lines']);
        });
    }

    // ── Evidence ──────────────────────────────────────────────────────────────

    /**
     * Attach a photo to a live claim. *"So show me the food that has gone bad."*
     *
     * `stage` is derived, never trusted from the client: whoever raised the claim
     * is making their case (`declared`), anyone else looking at it is inspecting
     * (`inspection`). That is what keeps the two sides of a disagreement
     * distinguishable afterwards.
     *
     * @param  \Illuminate\Http\UploadedFile  $file
     */
    public function attachPhoto(Wastage $wastage, $file, User $actor, ?string $caption = null): WastagePhoto
    {
        if (! $wastage->acceptsEvidence()) {
            throw new InventoryException(
                'This claim is already settled - its photos are the record of what was decided on, so nothing further can be added.'
            );
        }

        // Either end of the claim may add evidence: the branch that declared the
        // loss and the warehouse that has to sign for it.
        if (! $wastage->isVisibleTo($actor)) {
            throw new InventoryException('You cannot add evidence to a claim at a location you do not work with.');
        }

        $stage = (int) $wastage->recorded_by === (int) $actor->id ? 'declared' : 'inspection';

        // Read the file's own properties BEFORE storing it - once it has been
        // written to the disk the temp file is no longer something to depend on.
        // The mime type is SNIFFED, not taken from what the uploader claimed:
        // the gallery decides <img> versus <video> from this value, so a phone
        // that labels a .mov as image/jpeg would otherwise produce a permanently
        // broken thumbnail.
        $mime = $file->getMimeType() ?: $file->getClientMimeType();
        $size = $file->getSize();

        try {
            $path = $file->store("inventory/wastage/{$wastage->id}", 'public');
        } catch (\Throwable $e) {
            throw new InventoryException('That file could not be saved. Try again.');
        }

        // Smaller renditions for the grid and the lightbox. Returns null for
        // video, and for any image GD cannot read - in which case the row simply
        // carries no derivatives and every consumer falls back to the original.
        // Never allowed to fail the attach: the evidence matters, the thumbnail
        // does not.
        $derivatives = app(EvidenceImageProcessor::class)->process($path, $mime) ?? [];

        return $wastage->photos()->create([
            'stage' => $stage,
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
            'caption' => $caption !== null && trim($caption) !== '' ? trim($caption) : null,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'uploaded_by' => $actor->id,
            ...$derivatives,
        ]);
    }

    /**
     * Record a photo that is ALREADY on disk against a claim.
     *
     * The staged-upload path: the phone sent this while the record form was
     * still open, so the file was written before the claim existed. Re-storing
     * it would duplicate it on disk for no reason.
     *
     * Everything else matches `attachPhoto()` - the same evidence gate, the same
     * `stage` derived from the actor, the same derivatives - because a photo
     * that arrived early is not a lesser kind of evidence.
     */
    public function attachStoredPhoto(Wastage $wastage, UploadSessionFile $file, User $actor): WastagePhoto
    {
        if (! $wastage->acceptsEvidence()) {
            throw new InventoryException(
                'This claim is already settled - its photos are the record of what was decided on, so nothing further can be added.'
            );
        }
        if (! $wastage->isVisibleTo($actor)) {
            throw new InventoryException('You cannot add evidence to a claim at a location you do not work with.');
        }

        $stage = (int) $wastage->recorded_by === (int) $actor->id ? 'declared' : 'inspection';

        $derivatives = app(EvidenceImageProcessor::class)->process($file->path, $file->mime_type) ?? [];

        return $wastage->photos()->create([
            'stage' => $stage,
            'path' => $file->path,
            'url' => $file->url,
            'caption' => $file->caption,
            'mime_type' => $file->mime_type,
            'size_bytes' => $file->size_bytes,
            'uploaded_by' => $actor->id,
            ...$derivatives,
        ]);
    }

    /**
     * Remove a photo. Only the person who uploaded it, and only while the claim
     * is live - neither side gets to delete the other's evidence, and nobody
     * gets to edit the record after a decision has been made on it.
     */
    public function detachPhoto(Wastage $wastage, WastagePhoto $photo, User $actor): void
    {
        if ((int) $photo->wastage_id !== (int) $wastage->id) {
            throw new InventoryException('That photo does not belong to this claim.');
        }
        if (! $wastage->acceptsEvidence()) {
            throw new InventoryException('This claim is settled - its evidence can no longer be changed.');
        }
        if ((int) $photo->uploaded_by !== (int) $actor->id) {
            throw new InventoryException('Only whoever uploaded a photo can remove it.');
        }

        // Delete the row first: an orphaned file on disk is harmless, a row
        // pointing at a file that is gone renders as a broken image forever.
        $path = $photo->path;
        $thumb = $photo->thumb_path;
        $display = $photo->display_path;

        $photo->delete();

        Storage::disk('public')->delete($path);
        app(EvidenceImageProcessor::class)->forget($thumb, $display);
    }

    // ── Deciding ──────────────────────────────────────────────────────────────

    /**
     * The warehouse manager, with the returned goods in front of him, agrees the
     * loss is real. This is where an over-threshold claim finally hits the
     * ledger - at the disposal location, because the branch's stock already left
     * on the return transfer.
     */
    /**
     * @param  array<int,float>  $approvedQty  line_id => quantity allowed. Omitted
     *                                         lines are allowed in full.
     *
     * Partial approval is the reason the goods come back at all. *"So show me
     * the food that has gone bad."* Looking has three answers, and there were
     * only two buttons: a branch returns 20 kg claiming spoilage, the manager
     * opens the crate and 10 kg are fine. He had to write off all 20 or refuse
     * all 20 - destroy good food on paper, or call an honest claim a lie.
     *
     * What the branch DECLARED stays on the line untouched; what was allowed is
     * recorded beside it. The gap between the two is the record of how well a
     * branch judges its own stock.
     *
     * Goods not written off simply stay where they physically are - warehouse
     * stock, having arrived on the return transfer. They are not conjured back
     * to the branch. If the branch wants them, it requisitions them, which is
     * the honest way for them to travel and be accounted for.
     */
    public function approve(Wastage $wastage, User $actor, array $approvedQty = []): Wastage
    {
        $this->assertDecidable($wastage, $actor);
        $this->assertEvidenced($wastage);

        $wastage->loadMissing('lines');

        // Validate before anything is written, so a bad number cannot half-apply.
        foreach ($wastage->lines as $line) {
            if (! array_key_exists($line->id, $approvedQty)) {
                continue;
            }
            $granted = round((float) $approvedQty[$line->id], 4);

            if ($granted < 0) {
                throw new InventoryException('An approved quantity cannot be negative.');
            }
            if ($granted > (float) $line->quantity) {
                throw new InventoryException(
                    'You cannot write off more than was declared: the claim says '
                    .rtrim(rtrim((string) (float) $line->quantity, '0'), '.').', not '
                    .rtrim(rtrim((string) $granted, '0'), '.').'.'
                );
            }
        }

        $totalGranted = 0.0;
        foreach ($wastage->lines as $line) {
            $totalGranted += array_key_exists($line->id, $approvedQty)
                ? round((float) $approvedQty[$line->id], 4)
                : (float) $line->quantity;
        }

        // Allowing nothing is a refusal, and should be recorded as one rather
        // than as an approval that happens to write off zero.
        if ($totalGranted <= 0) {
            throw new InventoryException(
                'Nothing is being written off, so this is a refusal rather than an approval. '
                .'Refuse the claim and say why.'
            );
        }

        return DB::transaction(function () use ($wastage, $actor, $approvedQty) {
            foreach ($wastage->lines as $line) {
                $line->approved_qty = array_key_exists($line->id, $approvedQty)
                    ? round((float) $approvedQty[$line->id], 4)
                    : (float) $line->quantity;
                $line->save();
            }

            $wastage->status = WastageStatus::Approved;
            $wastage->approved_by = $actor->id;
            $wastage->approved_at = now();
            $wastage->save();

            $this->postWriteOff($wastage->fresh(['lines']), $actor);

            // The claim is now worth what was allowed, not what was asked for -
            // otherwise every wastage report overstates the loss.
            $this->revalueFromApproved($wastage->fresh(['lines']));

            return $wastage->fresh(['lines']);
        });
    }

    /** Re-total a claim against the approved quantities. */
    private function revalueFromApproved(Wastage $wastage): void
    {
        $total = 0.0;
        foreach ($wastage->lines as $line) {
            $qty = $line->approved_qty !== null ? (float) $line->approved_qty : (float) $line->quantity;
            $value = round($qty * (float) ($line->unit_cost ?? 0), 4);
            $line->line_value = $value;
            $line->save();
            $total += $value;
        }
        $wastage->total_value = round($total, 4);
        $wastage->save();
    }

    /**
     * He looked at it and disagrees. Nothing is written off.
     *
     * The goods are not conjured back to the branch: if they came back on a
     * return transfer they are sitting in warehouse stock, which is exactly where
     * the ledger already says they are. What changes is who carries the cost -
     * the claim failed, so the loss stays with whoever declared it and will
     * surface at their next count as an unexplained variance. That pressure is
     * the point of the whole mechanism.
     */
    public function reject(Wastage $wastage, User $actor, string $reason): Wastage
    {
        $this->assertDecidable($wastage, $actor);

        if (trim($reason) === '') {
            throw new InventoryException('Say why the claim is refused - a bare rejection tells the branch nothing.');
        }

        $wastage->status = WastageStatus::Rejected;
        $wastage->rejected_by = $actor->id;
        $wastage->rejected_at = now();
        $wastage->rejection_reason = $reason;
        $wastage->save();

        return $wastage->fresh(['lines']);
    }

    /**
     * The recorder withdraws their own claim. Only while nothing has moved: once
     * the goods are on their way back to the warehouse the return transfer is a
     * real stock movement and has to be seen through.
     */
    public function cancel(Wastage $wastage, User $actor): Wastage
    {
        if (! $wastage->status->isCancellable()) {
            throw new InventoryException("This wastage can no longer be withdrawn (status: {$wastage->status->value}).");
        }

        $return = $wastage->returnTransfer;
        if ($return !== null && ! in_array($return->status, [TransferStatus::Draft, TransferStatus::Approved, TransferStatus::Submitted], true)) {
            throw new InventoryException('The goods are already on their way back - this claim has to be seen through.');
        }

        return DB::transaction(function () use ($wastage, $actor, $return) {
            if ($return !== null && $return->status->isCancellable()) {
                $return->status = TransferStatus::Cancelled;
                $return->cancelled_by = $actor->id;
                $return->cancelled_at = now();
                $return->cancel_reason = "Wastage {$wastage->reference} withdrawn.";
                $return->save();
            }

            $wastage->status = WastageStatus::Cancelled;
            $wastage->cancelled_by = $actor->id;
            $wastage->cancelled_at = now();
            $wastage->save();

            return $wastage->fresh(['lines']);
        });
    }

    /**
     * The return transfer arrived at the warehouse. The goods are now where the
     * manager can look at them, so the claim moves from "awaiting return" to
     * "awaiting approval". Called by TransferService on receipt.
     */
    public function onReturnReceived(Transfer $transfer): void
    {
        $wastage = Wastage::whereKey($transfer->wastage_id)
            ->where('status', WastageStatus::PendingReturn->value)
            ->first();

        if ($wastage === null) {
            return;
        }

        $wastage->status = WastageStatus::PendingApproval;
        $wastage->disposal_location_id = $transfer->destination_location_id;
        $wastage->save();
    }

    // ── Classification-only origins (these post NOTHING) ───────────────────────

    /**
     * Reasons attached to a completed count. The count adjustments already
     * brought the ledger to what was physically there, so this record exists
     * solely to put those losses in the wastage report under a name.
     *
     * Only shortfalls are wastage. A line counted OVER what the ledger expected
     * is not a loss and never appears here - it is stock found, and the founder's
     * own framing covers it: "when they have surplus, which they have to answer
     * to."
     *
     * @param  array<int,array{item_id:int, unit_id:int, quantity:float, unit_cost:float|null, reason:string, reason_note:string|null}>  $lines
     */
    public function classifyCountVariance(
        int $locationId,
        array $lines,
        WastageOrigin $origin,
        string $sourceType,
        int $sourceId,
        User $actor,
        ?string $notes = null,
    ): ?Wastage {
        if ($lines === []) {
            return null;
        }

        $priced = array_map(function (array $row) {
            $qty = round(abs((float) $row['quantity']), 4);
            $cost = $row['unit_cost'] !== null ? (float) $row['unit_cost'] : null;

            return [
                'item_id' => (int) $row['item_id'],
                'unit_id' => (int) $row['unit_id'],
                'quantity' => $qty,
                'unit_cost' => $cost,
                'line_value' => round($qty * (float) ($cost ?? 0), 4),
                'reason' => $row['reason'],
                'reason_note' => $row['reason_note'] ?? null,
                'movement_id' => null,
            ];
        }, $lines);

        $totalValue = round(array_sum(array_column($priced, 'line_value')), 4);

        $wastage = Wastage::create([
            'reference' => $this->references->wastage(),
            'location_id' => $locationId,
            'disposal_location_id' => $locationId,
            'claimant_location_id' => $locationId,
            'origin' => $origin,
            // Already reflected in the ledger and already explained. There is
            // nothing left to approve, so it is filed as settled.
            'status' => WastageStatus::Approved,
            'total_value' => $totalValue,
            'threshold_amount' => self::threshold(),
            'requires_approval' => false,
            'requires_return' => false,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'notes' => $notes,
            'recorded_by' => $actor->id,
            'recorded_at' => now(),
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        $wastage->lines()->createMany($priced);

        if ($totalValue > self::threshold()) {
            $this->raiseThresholdAlert($wastage);
        }

        return $wastage->fresh(['lines']);
    }

    /**
     * A disputed shortfall the warehouse decided to stop chasing.
     *
     * The stock left the source at `send` and never arrived anywhere, so the
     * ledger recorded this loss the moment the short receipt was entered. What
     * was missing was any record that would let it show up in a wastage report,
     * attributed to the leg it went missing on - this is that record, and it
     * moves nothing.
     *
     * @param  array<int,array{item_id:int, unit_id:int, quantity:float}>  $shortfalls
     */
    public function classifyTransferShortfall(
        Transfer $transfer,
        array $shortfalls,
        User $actor,
        ?string $notes = null,
    ): ?Wastage {
        if ($shortfalls === []) {
            return null;
        }

        $sourceId = (int) $transfer->source_location_id;

        $lines = array_map(fn (array $row) => [
            'item_id' => (int) $row['item_id'],
            'unit_id' => (int) $row['unit_id'],
            'quantity' => round((float) $row['quantity'], 4),
            'unit_cost' => $this->weightedCost((int) $row['item_id'], $sourceId),
            'reason' => WastageReason::TransferShortfall->value,
            'reason_note' => $notes,
        ], $shortfalls);

        return $this->classifyCountVariance(
            locationId: $sourceId,
            lines: $lines,
            origin: WastageOrigin::TransferShortfall,
            sourceType: 'inventory_transfer',
            sourceId: (int) $transfer->id,
            actor: $actor,
            notes: $notes ?? "Written off from {$transfer->reference}.",
        );
    }

    /**
     * A consignment refused at the door. The stock went straight back to the
     * source, so the goods are the sender's again - and the claim lands in the
     * sender's queue for them to write off or put back on the shelf.
     *
     * Unlike the other classification helpers this one DOES post on approval:
     * the goods are sitting in the source's stock and nothing has written them
     * down yet.
     *
     * @param  array<int,array{item_id:int, unit_id:int, quantity:float}>  $lines
     */
    public function raiseFromDeliveryRejection(
        Transfer $transfer,
        array $lines,
        WastageReason $reason,
        ?string $reasonNote,
        User $actor,
    ): ?Wastage {
        if ($lines === []) {
            return null;
        }

        $sourceId = (int) $transfer->source_location_id;

        $priced = array_map(function (array $row) use ($sourceId, $reason, $reasonNote) {
            $qty = round((float) $row['quantity'], 4);
            $cost = $this->weightedCost((int) $row['item_id'], $sourceId);

            return [
                'item_id' => (int) $row['item_id'],
                'unit_id' => (int) $row['unit_id'],
                'quantity' => $qty,
                'unit_cost' => $cost,
                'line_value' => round($qty * (float) ($cost ?? 0), 4),
                'reason' => $reason->value,
                'reason_note' => $reasonNote,
                'movement_id' => null,
            ];
        }, $lines);

        $totalValue = round(array_sum(array_column($priced, 'line_value')), 4);

        $wastage = Wastage::create([
            'reference' => $this->references->wastage(),
            'location_id' => $sourceId,
            'disposal_location_id' => $sourceId,
            // The goods are the sender's, but the person who saw what was wrong
            // with them works at the destination. Without this they cannot open
            // the claim they just raised, let alone photograph the evidence.
            'claimant_location_id' => (int) $transfer->destination_location_id,
            'origin' => WastageOrigin::DeliveryRejection,
            'status' => WastageStatus::PendingApproval,
            'total_value' => $totalValue,
            'threshold_amount' => self::threshold(),
            'requires_approval' => true,
            'requires_return' => false,
            'source_type' => 'inventory_transfer',
            'source_id' => (int) $transfer->id,
            'notes' => "Raised from {$transfer->reference}, refused on delivery.",
            'recorded_by' => $actor->id,
            'recorded_at' => now(),
        ]);

        $wastage->lines()->createMany($priced);

        if ($totalValue > self::threshold()) {
            $this->raiseThresholdAlert($wastage);
        }

        return $wastage->fresh(['lines']);
    }

    // ── Ledger ────────────────────────────────────────────────────────────────

    /**
     * Write the stock down. FEFO, so the oldest goods go first, and one movement
     * per source batch exactly as production and sales do.
     *
     * Silently does nothing for classification-only origins - see rule 1 at the
     * top of this class. That guard is load-bearing: without it, approving a
     * closing-variance record would deduct the same missing rice a second time.
     */
    private function postWriteOff(Wastage $wastage, User $actor): void
    {
        if (! $wastage->origin->postsStock()) {
            return;
        }

        $locationId = $wastage->postingLocationId();
        $wastage->loadMissing('lines');

        foreach ($wastage->lines as $line) {
            $itemId = (int) $line->item_id;
            // What the approver allowed, falling back to the declared amount for
            // claims that never went through a decision (self-approved, or
            // settled before partial approval existed).
            $qty = $line->approved_qty !== null ? (float) $line->approved_qty : (float) $line->quantity;
            if ($qty <= 0) {
                continue;
            }

            $avgCost = $this->weightedCost($itemId, $locationId);
            $first = null;

            foreach ($this->batches->allocate($itemId, $locationId, $qty) as $i => $alloc) {
                $movement = $this->posting->post([
                    'item_id' => $itemId,
                    'location_id' => $locationId,
                    'quantity' => -1 * $alloc['qty'], // negative = stock out
                    'movement_type' => 'wastage',
                    'reference_type' => 'inventory_wastage',
                    'reference_id' => (int) $wastage->id,
                    'batch_id' => $alloc['batch_id'],
                    'unit_cost_at_time' => $alloc['unit_cost'] ?? $avgCost ?? (float) $line->unit_cost,
                    'user_id' => $actor->id,
                    'idempotency_key' => "wastage:{$wastage->id}:line:{$line->id}:i:{$i}",
                    'occurred_at' => now(),
                ]);
                $first ??= $movement;
            }

            if ($first !== null) {
                $line->movement_id = $first->id;
                $line->save();
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build and value the declared lines. Valued at the location's weighted
     * average cost, which is what the threshold is then measured against.
     *
     * @param  array<int,array{item_id:int, quantity:float, reason:string, reason_note?:string|null}>  $rows
     * @return array<int,array<string,mixed>>
     */
    private function buildLines(array $rows, int $locationId, bool $checkOnHand): array
    {
        $itemIds = array_map(fn ($r) => (int) $r['item_id'], $rows);
        $items = Item::whereIn('id', $itemIds)->get(['id', 'name', 'base_unit_id'])->keyBy('id');

        // Aggregate demand per item first: two lines of the same item for two
        // different reasons must not each pass an on-hand check the pair fails.
        $demand = [];
        foreach ($rows as $row) {
            $demand[(int) $row['item_id']] = ($demand[(int) $row['item_id']] ?? 0) + (float) $row['quantity'];
        }

        $built = [];
        foreach ($rows as $row) {
            $itemId = (int) $row['item_id'];
            $item = $items[$itemId] ?? null;
            if ($item === null) {
                throw new InventoryException("Inventory item {$itemId} does not exist.");
            }

            $qty = round((float) $row['quantity'], 4);
            if ($qty <= 0) {
                throw new InventoryException('Wasted quantity must be greater than zero.');
            }

            $reason = WastageReason::tryFrom((string) $row['reason']);
            if ($reason === null) {
                throw new InventoryException("'{$row['reason']}' is not a wastage reason.");
            }
            $note = isset($row['reason_note']) ? trim((string) $row['reason_note']) : '';
            if ($reason->requiresNote() && $note === '') {
                throw new InventoryException("Choosing 'Other' for {$item->name} means saying what happened - add a note.");
            }

            if ($checkOnHand) {
                $onHand = $this->onHand($itemId, $locationId);
                if (round($demand[$itemId], 4) > $onHand) {
                    throw new InventoryException(
                        "You cannot waste more {$item->name} than you hold: {$onHand} on hand, {$demand[$itemId]} declared."
                    );
                }
            }

            $cost = $this->weightedCost($itemId, $locationId);

            $built[] = [
                'item_id' => $itemId,
                'unit_id' => (int) $item->base_unit_id,
                'quantity' => $qty,
                'unit_cost' => $cost,
                'line_value' => round($qty * (float) ($cost ?? 0), 4),
                'reason' => $reason->value,
                'reason_note' => $note !== '' ? $note : null,
                'movement_id' => null,
            ];
        }

        return $built;
    }

    /**
     * Raise the branch → warehouse transfer that carries the goods back for
     * inspection. Created ready to send, because the decision to return was
     * taken the moment the claim was filed - there is nothing further to approve
     * at the branch end.
     */
    private function raiseReturnTransfer(Wastage $wastage, User $actor): void
    {
        $wastage->loadMissing('lines');

        $transfer = Transfer::create([
            'reference' => $this->references->transfer(),
            'source_location_id' => $wastage->location_id,
            'destination_location_id' => $wastage->disposal_location_id,
            'status' => TransferStatus::Approved,
            'wastage_id' => $wastage->id,
            'notes' => "Return of goods declared bad on {$wastage->reference}.",
            'created_by' => $actor->id,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        // Collapse per-reason lines: the lorry carries quantities, not reasons.
        $perItem = [];
        foreach ($wastage->lines as $line) {
            $perItem[(int) $line->item_id] = ($perItem[(int) $line->item_id] ?? 0) + (float) $line->quantity;
        }

        $units = Item::whereIn('id', array_keys($perItem))->pluck('base_unit_id', 'id');

        $transfer->lines()->createMany(array_map(fn ($itemId) => [
            'item_id' => $itemId,
            'unit_id' => $units[$itemId],
            'requested_qty' => round($perItem[$itemId], 4),
        ], array_keys($perItem)));

        $wastage->return_transfer_id = $transfer->id;
        $wastage->save();
    }

    /**
     * Which warehouse do these goods go back to? The one that most recently
     * supplied this location, because that is the manager who answers for them.
     * Falls back to the first active warehouse when nothing has been delivered
     * here yet.
     */
    private function resolveWarehouseFor(int $branchLocationId): int
    {
        $lastSupplier = Transfer::query()
            ->where('destination_location_id', $branchLocationId)
            ->whereIn('status', [
                TransferStatus::Received->value,
                TransferStatus::Closed->value,
                TransferStatus::Disputed->value,
                TransferStatus::ClosedDisputed->value,
            ])
            ->join('inventory_locations as l', 'l.id', '=', 'inventory_transfers.source_location_id')
            ->where('l.type', 'warehouse')
            ->where('l.is_active', true)
            ->orderByDesc('inventory_transfers.received_at')
            ->value('inventory_transfers.source_location_id');

        if ($lastSupplier !== null) {
            return (int) $lastSupplier;
        }

        $warehouse = Location::where('type', 'warehouse')
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        if ($warehouse === null) {
            throw new InventoryException(
                'Goods worth more than the threshold have to go back to a warehouse, and no active warehouse is configured.'
            );
        }

        return (int) $warehouse;
    }

    private function raiseThresholdAlert(Wastage $wastage): void
    {
        Alert::updateOrCreate(
            [
                'type' => 'wastage_threshold',
                'reference_type' => 'inventory_wastage',
                'reference_id' => $wastage->id,
            ],
            [
                'severity' => 'critical',
                'status' => 'open',
                'location_id' => $wastage->location_id,
                'message' => sprintf(
                    '%s: GHS %s of stock declared wasted - above the GHS %s threshold.',
                    $wastage->reference,
                    number_format((float) $wastage->total_value, 2),
                    number_format(self::threshold(), 2),
                ),
                'context' => [
                    'wastage_id' => $wastage->id,
                    'total_value' => (float) $wastage->total_value,
                    'threshold' => self::threshold(),
                    'origin' => $wastage->origin->value,
                ],
            ],
        );
    }

    /**
     * Nobody signs off their own claim. The whole point of the warehouse
     * manager's approval is that a second pair of eyes has seen the goods -
     * which is worth nothing if the eyes belong to the person who filed it.
     */
    private function assertDecidable(Wastage $wastage, User $actor): void
    {
        if (! $wastage->status->canDecide()) {
            throw new InventoryException(
                $wastage->status === WastageStatus::PendingReturn
                    ? 'The goods have not come back to the warehouse yet, so there is nothing to look at.'
                    : "This wastage has already been settled (status: {$wastage->status->value})."
            );
        }

        if ((int) $wastage->recorded_by === (int) $actor->id) {
            throw new InventoryException('You recorded this wastage, so someone else has to approve it.');
        }

        $this->assertOperatesAt(
            $actor,
            $wastage->postingLocationId(),
            $wastage->disposalLocation?->name ?? $wastage->location?->name,
        );
    }

    /**
     * *"So show me the food that has gone bad."*
     *
     * Above the threshold a claim is real money written off on somebody's word,
     * so there has to be something to look at.
     *
     * Two deliberate placements. It gates APPROVAL, not declaration - the branch
     * should be able to raise the claim the moment it happens and photograph the
     * goods before the lorry leaves, and it is the approver, the one who has to
     * justify the decision, who must not sign off on nothing.
     *
     * And it gates approval ONLY, never rejection. A claim arriving with no
     * evidence is a reason to refuse it; requiring a photo before you may say
     * "no" would trap unevidenced claims open forever.
     */
    private function assertEvidenced(Wastage $wastage): void
    {
        if (! $wastage->requires_approval || (float) $wastage->total_value <= self::threshold()) {
            return;
        }

        if ($wastage->photos()->count() === 0) {
            throw new InventoryException(
                'There is no photo of these goods. Ask whoever declared the loss to add one before you write off GHS '
                .number_format((float) $wastage->total_value, 2).' of stock - or refuse the claim.'
            );
        }
    }

    /** Overseeing a location is not the same as working at one. */
    private function assertOperatesAt(User $actor, int $locationId, ?string $locationName): void
    {
        $operating = $actor->operatingLocationIds();

        if ($operating === null || in_array($locationId, array_map('intval', $operating), true)) {
            return;
        }

        $where = $locationName ?? 'that location';
        throw new InventoryException("These goods are at {$where}. Only someone there can account for them.");
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
}
