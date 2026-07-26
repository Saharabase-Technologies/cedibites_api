<?php

use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Domain\Inventory\Requisitions\RequisitionService;
use App\Domain\Inventory\Transfers\TransferService;
use App\Enums\Inventory\RequisitionStatus;
use App\Enums\Inventory\TransferStatus;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\Inventory\Requisition;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Inventory\Transfer;
use App\Models\User;

beforeEach(function () {
    $this->engine = app(MovementPostingEngine::class);
    $this->requisitions = app(RequisitionService::class);
    $this->transfers = app(TransferService::class);
    $this->actor = User::factory()->create();
    // Separation of duties: the requester may not approve their own request.
    $this->approver = User::factory()->create();

    $this->warehouse = Location::factory()->warehouse()->create();
    $destBranch = Branch::factory()->create();
    $this->branch = Location::factory()->satellite()->create(['branch_id' => $destBranch->id]);

    // The sender may not sign for arrival, and each end accounts only for its
    // own side — so the receiver has to actually be posted at the destination.
    $this->receiver = User::factory()->create();
    Employee::factory()->create(['user_id' => $this->receiver->id])
        ->branches()->attach($destBranch->id);

    $this->item = Item::factory()->create();

    // 100 on hand at the warehouse.
    $this->engine->post([
        'item_id' => $this->item->id,
        'location_id' => $this->warehouse->id,
        'quantity' => 100,
        'movement_type' => 'purchase',
        'unit_cost_at_time' => 5.0,
        'idempotency_key' => 'seed-requisition',
    ]);
});

function draftRequisition($test, float $qty): Requisition
{
    return $test->requisitions->create([
        'requesting_location_id' => $test->branch->id,
        'source_location_id' => $test->warehouse->id,
        'purpose' => 'supplementary',
        'items' => [['item_id' => $test->item->id, 'requested_qty' => $qty]],
    ], $test->actor);
}

/**
 * Drive a spawned transfer all the way to received. It arrives already
 * approved — approving the requisition is the approval — so it goes straight
 * to send.
 */
function fulfilTransfer($test, Transfer $transfer): Transfer
{
    // A requisition-spawned transfer arrives approved; a corrective one spawned
    // by a dispute still starts as a draft.
    if ($transfer->status === TransferStatus::Draft) {
        $transfer = $test->transfers->submit($transfer, $test->actor);
        $transfer = $test->transfers->approve($transfer, $test->actor);
    }

    $transfer = $test->transfers->send($transfer, $test->actor);

    return $test->transfers->receive($transfer, $test->receiver);
}

it('approves a requisition, spawns a fulfilling transfer, and auto-fulfils on receipt', function () {
    $r = draftRequisition($this, 20);
    expect($r->status)->toBe(RequisitionStatus::Draft);

    $r = $this->requisitions->submit($r, $this->actor);
    expect($r->status)->toBe(RequisitionStatus::Submitted);

    $r = $this->requisitions->approve($r, $this->approver);
    expect($r->status)->toBe(RequisitionStatus::Approved)
        ->and($r->fulfilling_transfer_id)->not->toBeNull();

    $transfer = Transfer::find($r->fulfilling_transfer_id);
    expect($transfer->requisition_id)->toBe($r->id)
        // Ready to send — approving the requisition already approved it.
        ->and($transfer->status)->toBe(TransferStatus::Approved)
        ->and($transfer->source_location_id)->toBe($this->warehouse->id)
        ->and($transfer->destination_location_id)->toBe($this->branch->id)
        ->and((float) $transfer->lines()->first()->requested_qty)->toBe(20.0);

    fulfilTransfer($this, $transfer);

    expect($r->fresh()->status)->toBe(RequisitionStatus::Fulfilled)
        ->and($r->fresh()->fulfilled_at)->not->toBeNull();
});

it('trims the granted quantity and transfers only what was approved', function () {
    $r = draftRequisition($this, 40);
    $r = $this->requisitions->submit($r, $this->actor);
    $lineId = $r->lines()->first()->id;

    $r = $this->requisitions->approve($r, $this->approver, [$lineId => 25]);

    $transfer = Transfer::find($r->fulfilling_transfer_id);
    expect((float) $r->lines()->first()->approved_qty)->toBe(25.0)
        ->and((float) $transfer->lines()->first()->requested_qty)->toBe(25.0);
});

it('rejects a submitted requisition with a reason and spawns no transfer', function () {
    $r = draftRequisition($this, 10);
    $r = $this->requisitions->submit($r, $this->actor);
    $r = $this->requisitions->reject($r, $this->actor, 'Out of stock this week.');

    expect($r->status)->toBe(RequisitionStatus::Rejected)
        ->and($r->rejection_reason)->toBe('Out of stock this week.')
        ->and($r->fulfilling_transfer_id)->toBeNull();
});

it('keeps the requisition unfulfilled after a disputed receipt until the corrective arrives', function () {
    $r = draftRequisition($this, 30);
    $r = $this->requisitions->submit($r, $this->actor);
    $r = $this->requisitions->approve($r, $this->approver);
    $transfer = Transfer::find($r->fulfilling_transfer_id);

    $transfer = $this->transfers->send($transfer, $this->actor);
    $lineId = $transfer->lines()->first()->id;
    $transfer = $this->transfers->receive($transfer, $this->receiver, [$lineId => 20]); // 10 short

    expect($transfer->fresh()->status)->toBe(TransferStatus::Disputed)
        ->and($r->fresh()->status)->toBe(RequisitionStatus::Approved); // not yet fulfilled

    $this->transfers->resolveDispute($transfer, $this->actor, 'short');
    $corrective = Transfer::where('parent_transfer_id', $transfer->id)->first();
    expect($corrective->requisition_id)->toBe($r->id);

    fulfilTransfer($this, $corrective);

    expect($r->fresh()->status)->toBe(RequisitionStatus::Fulfilled);
});

it('blocks approval when no line is granted a positive quantity', function () {
    $r = draftRequisition($this, 15);
    $r = $this->requisitions->submit($r, $this->actor);
    $lineId = $r->lines()->first()->id;

    expect(fn () => $this->requisitions->approve($r, $this->approver, [$lineId => 0]))
        ->toThrow(App\Domain\Inventory\Exceptions\InventoryException::class);
});
