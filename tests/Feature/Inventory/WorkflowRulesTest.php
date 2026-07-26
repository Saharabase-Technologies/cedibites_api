<?php

use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Domain\Inventory\Requisitions\RequisitionService;
use App\Domain\Inventory\Transfers\TransferService;
use App\Enums\Inventory\RequisitionStatus;
use App\Enums\Inventory\TransferStatus;
use App\Models\Inventory\DisputeResolution;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\Inventory\Requisition;
use App\Models\Inventory\Transfer;
use App\Models\User;

/**
 * Workflow rules the client asked for after walking the portal:
 * approval should not have to be given four times, whoever sends stock should
 * not also sign for it, a draft is private and disposable, and a shortfall may
 * be written off instead of chased.
 */
beforeEach(function () {
    $this->engine = app(MovementPostingEngine::class);
    $this->requisitions = app(RequisitionService::class);
    $this->transfers = app(TransferService::class);

    $this->actor = User::factory()->create();
    $this->receiver = User::factory()->create();
    $this->warehouse = Location::factory()->warehouse()->create();
    $this->branch = Location::factory()->satellite()->create();
    $this->item = Item::factory()->create();

    $this->engine->post([
        'item_id' => $this->item->id,
        'location_id' => $this->warehouse->id,
        'quantity' => 100,
        'movement_type' => 'purchase',
        'unit_cost_at_time' => 5.0,
        'idempotency_key' => 'seed-workflow',
    ]);
});

function submittedRequisition($test, float $qty = 20, ?User $author = null): Requisition
{
    $r = $test->requisitions->create([
        'requesting_location_id' => $test->branch->id,
        'source_location_id' => $test->warehouse->id,
        'items' => [['item_id' => $test->item->id, 'requested_qty' => $qty]],
    ], $author ?? $test->actor);

    return $test->requisitions->submit($r, $author ?? $test->actor);
}

// ── Approval should not be given four times ──────────────────────────────────

it('lands the spawned transfer ready to send, not back in draft', function () {
    $r = $this->requisitions->approve(submittedRequisition($this), $this->actor);
    $transfer = Transfer::find($r->fulfilling_transfer_id);

    expect($transfer->status)->toBe(TransferStatus::Approved)
        ->and($transfer->status->canSend())->toBeTrue();

    // Straight to send — no submit/approve round trip.
    $sent = $this->transfers->send($transfer, $this->actor);
    expect($sent->status)->toBe(TransferStatus::Sent);
});

it('refuses to approve a requisition the warehouse cannot cover', function () {
    // 500 requested against 100 on hand.
    expect(fn () => $this->requisitions->approve(submittedRequisition($this, 500), $this->actor))
        ->toThrow(Exception::class, 'Source stock is short');
});

it('lets an override push an approval through a short source', function () {
    $r = $this->requisitions->approve(submittedRequisition($this, 500), $this->actor, [], override: true);

    expect(Transfer::find($r->fulfilling_transfer_id)->status)->toBe(TransferStatus::Approved);
});

// ── Whoever sends may not also receive ───────────────────────────────────────

it('blocks the sender from receiving their own transfer', function () {
    $r = $this->requisitions->approve(submittedRequisition($this), $this->actor);
    $transfer = $this->transfers->send(Transfer::find($r->fulfilling_transfer_id), $this->actor);

    expect(fn () => $this->transfers->receive($transfer, $this->actor))
        ->toThrow(Exception::class, 'cannot also receive it');
});

it('lets anyone else at the destination receive it', function () {
    $r = $this->requisitions->approve(submittedRequisition($this), $this->actor);
    $transfer = $this->transfers->send(Transfer::find($r->fulfilling_transfer_id), $this->actor);

    expect($this->transfers->receive($transfer, $this->receiver)->status)
        ->toBe(TransferStatus::Received);
});

// ── Drafts are private and disposable ────────────────────────────────────────

it('deletes a draft requisition', function () {
    $r = $this->requisitions->create([
        'requesting_location_id' => $this->branch->id,
        'source_location_id' => $this->warehouse->id,
        'items' => [['item_id' => $this->item->id, 'requested_qty' => 5]],
    ], $this->actor);

    $this->requisitions->delete($r, $this->actor);

    expect(Requisition::find($r->id))->toBeNull();
});

it('refuses to delete a requisition that is already a record', function () {
    $r = submittedRequisition($this);

    expect(fn () => $this->requisitions->delete($r, $this->actor))
        ->toThrow(Exception::class, 'Only draft requisitions can be deleted');
});

it('refuses to let someone delete a draft they did not start', function () {
    $r = $this->requisitions->create([
        'requesting_location_id' => $this->branch->id,
        'source_location_id' => $this->warehouse->id,
        'items' => [['item_id' => $this->item->id, 'requested_qty' => 5]],
    ], $this->actor);

    expect(fn () => $this->requisitions->delete($r, $this->receiver))
        ->toThrow(Exception::class, 'Only the person who started this draft');
});

it('hides a draft from everyone but its author, and shows it once submitted', function () {
    $mine = $this->requisitions->create([
        'requesting_location_id' => $this->branch->id,
        'source_location_id' => $this->warehouse->id,
        'items' => [['item_id' => $this->item->id, 'requested_qty' => 5]],
    ], $this->actor);

    expect(Requisition::visibleDrafts($this->receiver)->pluck('id'))->not->toContain($mine->id)
        ->and(Requisition::visibleDrafts($this->actor)->pluck('id'))->toContain($mine->id)
        ->and($mine->isHiddenDraftFor($this->receiver))->toBeTrue();

    $this->requisitions->submit($mine, $this->actor);

    expect(Requisition::visibleDrafts($this->receiver)->pluck('id'))->toContain($mine->id);
});

// ── A shortfall may be written off instead of chased ─────────────────────────

/** Drive a requisition to a short receipt, leaving the transfer disputed. */
function disputedTransfer($test, float $requested, float $received): Transfer
{
    $r = $test->requisitions->approve(submittedRequisition($test, $requested), $test->actor);
    $transfer = $test->transfers->send(Transfer::find($r->fulfilling_transfer_id), $test->actor);
    $lineId = $transfer->lines()->first()->id;

    return $test->transfers->receive($transfer, $test->receiver, [$lineId => $received]);
}

it('writes the shortfall off instead of spawning a corrective transfer', function () {
    $transfer = disputedTransfer($this, 20, 15);
    expect($transfer->status)->toBe(TransferStatus::Disputed);

    $before = Transfer::count();
    $this->transfers->resolveDispute($transfer, $this->actor, 'gone bad in transit', sendCorrective: false);

    $dispute = DisputeResolution::where('transfer_id', $transfer->id)->first();

    expect(Transfer::count())->toBe($before) // nothing spawned
        ->and($dispute->resolution)->toBe('written_off')
        ->and((float) $dispute->written_off_qty)->toBe(5.0)
        ->and($dispute->corrective_transfer_id)->toBeNull()
        ->and($transfer->fresh()->status)->toBe(TransferStatus::ClosedDisputed);
});

it('still spawns a corrective transfer by default', function () {
    $transfer = disputedTransfer($this, 20, 15);
    $this->transfers->resolveDispute($transfer, $this->actor, 'resend it');

    $dispute = DisputeResolution::where('transfer_id', $transfer->id)->first();

    expect($dispute->resolution)->toBe('corrective')
        ->and($dispute->corrective_transfer_id)->not->toBeNull()
        ->and((float) $dispute->written_off_qty)->toBe(0.0);
});

it('reports the whole corrective chain from any point in it', function () {
    $first = disputedTransfer($this, 20, 15);
    $this->transfers->resolveDispute($first, $this->actor, 'resend');

    $corrective = Transfer::where('parent_transfer_id', $first->id)->first();

    $chain = $this->transfers->lineage($corrective);

    expect($chain)->toHaveCount(2)
        ->and($chain[0]['reference'])->toBe($first->reference)
        ->and($chain[0]['depth'])->toBe(0)
        ->and($chain[0]['is_current'])->toBeFalse()
        // Asked from the child, the parent is still reported — the old view
        // could only see one hop.
        ->and($chain[1]['id'])->toBe($corrective->id)
        ->and($chain[1]['depth'])->toBe(1)
        ->and($chain[1]['is_current'])->toBeTrue();
});

// ── Availability is answerable before anything is committed ──────────────────

it('answers whether the source can cover a demand', function () {
    $ok = $this->transfers->checkAvailability($this->warehouse->id, [
        ['item_id' => $this->item->id, 'qty' => 40],
    ]);
    $short = $this->transfers->checkAvailability($this->warehouse->id, [
        ['item_id' => $this->item->id, 'qty' => 140],
    ]);

    expect($ok[0]['sufficient'])->toBeTrue()
        ->and($ok[0]['available'])->toBe(100.0)
        ->and($ok[0]['shortfall'])->toBe(0.0)
        ->and($short[0]['sufficient'])->toBeFalse()
        ->and($short[0]['shortfall'])->toBe(40.0);
});

it('aggregates demand for the same item across several lines', function () {
    $rows = $this->transfers->checkAvailability($this->warehouse->id, [
        ['item_id' => $this->item->id, 'qty' => 60],
        ['item_id' => $this->item->id, 'qty' => 60],
    ]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['required'])->toBe(120.0)
        ->and($rows[0]['sufficient'])->toBeFalse();
});
