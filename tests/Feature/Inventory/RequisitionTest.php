<?php

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Domain\Inventory\Requisitions\RequisitionService;
use App\Domain\Inventory\Transfers\TransferService;
use App\Enums\Inventory\RequisitionStatus;
use App\Enums\Inventory\TransferStatus;
use App\Enums\Inventory\WastageReason;
use App\Enums\Permission;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\Inventory\Requisition;
use App\Models\Inventory\Transfer;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    // VIEW_ALL_FOR_TESTS: outbound acts (submit/approve/send) are gated to the
    // SOURCE location. The warehouse has no branch, so whoever dispatches from
    // it must hold view_all_locations — that is what makes them a warehouse
    // operator rather than branch staff.
    $this->seed(PermissionSeeder::class);
    $this->engine = app(MovementPostingEngine::class);
    $this->requisitions = app(RequisitionService::class);
    $this->transfers = app(TransferService::class);
    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(Permission::InventoryViewAllLocations->value);
    // Separation of duties: the requester may not approve their own request.
    $this->approver = User::factory()->create();
    $this->approver->givePermissionTo(Permission::InventoryViewAllLocations->value);

    $this->warehouse = Location::factory()->warehouse()->create();
    $destBranch = Branch::factory()->create();
    $this->branch = Location::factory()->satellite()->create(['branch_id' => $destBranch->id]);

    // The sender may not sign for arrival, and each end accounts only for its
    // own side — so the receiver has to actually be posted at the destination.
    $this->receiver = User::factory()->create();
    Employee::factory()->create(['user_id' => $this->receiver->id])
        ->branches()->attach($destBranch->id);

    // A requisition is a BRANCH asking the warehouse to supply it, so it has to
    // be raised by someone who works at that branch. `$this->actor` holds
    // view_all_locations, which makes it a warehouse operator, and a warehouse
    // supplies stock rather than requesting it.
    $this->requester = User::factory()->create();
    Employee::factory()->create(['user_id' => $this->requester->id])
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
    ], $test->requester);
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

/*
 * A refused delivery closes the request SHORT.
 *
 * This used to strand: the receive path deliberately withheld fulfilment when
 * anything was refused, "for a corrective run" - but nothing ever performed that
 * run, so the requisition sat on `approved` forever, reading as still-on-its-way
 * long after the lorry had been and gone. Two live requisitions on production
 * were already in that state.
 *
 * The delivery happened. What went back is on the wastage claim. If the branch
 * still needs the goods it asks again - a refusal is not an automatic obligation
 * on the warehouse to send more.
 */
it('closes the requisition short when part of the delivery is refused at the door', function () {
    $r = draftRequisition($this, 30);
    $r = $this->requisitions->submit($r, $this->actor);
    $r = $this->requisitions->approve($r, $this->approver);

    $transfer = $this->transfers->send(Transfer::find($r->fulfilling_transfer_id), $this->actor);
    $lineId = $transfer->lines()->first()->id;

    // 22 kept, 8 turned away. Nothing is missing, so this is not a dispute.
    $transfer = $this->transfers->receive(
        $transfer,
        $this->receiver,
        [$lineId => 22],
        null,
        [$lineId => ['qty' => 8, 'reason' => WastageReason::Spoiled->value]],
    );

    expect($transfer->fresh()->status)->toBe(TransferStatus::Received)
        ->and($r->fresh()->status)->toBe(RequisitionStatus::FulfilledShort)
        // Terminal: it must stop showing up as an open request.
        ->and($r->fresh()->status->isTerminal())->toBeTrue()
        ->and($r->fresh()->status->isDelivered())->toBeTrue()
        ->and($r->fresh()->fulfilled_at)->not->toBeNull();
});

it('closes the requisition short when the whole consignment is turned away', function () {
    $r = draftRequisition($this, 12);
    $r = $this->requisitions->submit($r, $this->actor);
    $r = $this->requisitions->approve($r, $this->approver);

    $transfer = $this->transfers->send(Transfer::find($r->fulfilling_transfer_id), $this->actor);
    $lineId = $transfer->lines()->first()->id;

    $transfer = $this->transfers->receive(
        $transfer,
        $this->receiver,
        [$lineId => 0],
        'The whole lot is off.',
        [$lineId => ['qty' => 12, 'reason' => WastageReason::Spoiled->value]],
    );

    // The transfer is rejected outright, but the REQUEST is still finished -
    // leaving it open would put a phantom delivery in the branch's queue.
    expect($transfer->fresh()->status)->toBe(TransferStatus::Rejected)
        ->and($r->fresh()->status)->toBe(RequisitionStatus::FulfilledShort);
});

it('still says plainly fulfilled when the branch keeps everything', function () {
    $r = draftRequisition($this, 15);
    $r = $this->requisitions->submit($r, $this->actor);
    $r = $this->requisitions->approve($r, $this->approver);

    fulfilTransfer($this, Transfer::find($r->fulfilling_transfer_id));

    // The short status must not leak into the clean path.
    expect($r->fresh()->status)->toBe(RequisitionStatus::Fulfilled);
});

it('does not let a corrective transfer downgrade a requisition already closed', function () {
    $r = draftRequisition($this, 30);
    $r = $this->requisitions->submit($r, $this->actor);
    $r = $this->requisitions->approve($r, $this->approver);

    $transfer = $this->transfers->send(Transfer::find($r->fulfilling_transfer_id), $this->actor);
    $lineId = $transfer->lines()->first()->id;
    $transfer = $this->transfers->receive($transfer, $this->receiver, [$lineId => 20]); // 10 short

    // A dispute leaves it open, because a corrective IS coming.
    expect($r->fresh()->status)->toBe(RequisitionStatus::Approved);

    $this->transfers->resolveDispute($transfer, $this->actor, 'short');
    $corrective = Transfer::where('parent_transfer_id', $transfer->id)->first();

    // The corrective is itself part-refused. It closes short, and a second
    // receipt afterwards must not move it again - `fulfilRequisition` is guarded
    // on `approved` precisely so a late arrival cannot rewrite the ending.
    $corrective = $this->transfers->submit($corrective, $this->actor);
    $corrective = $this->transfers->approve($corrective->fresh(), $this->actor);
    $corrective = $this->transfers->send($corrective->fresh('lines'), $this->actor);
    $cLineId = $corrective->lines()->first()->id;
    $this->transfers->receive(
        $corrective,
        $this->receiver,
        [$cLineId => 8],
        null,
        [$cLineId => ['qty' => 2, 'reason' => WastageReason::Spoiled->value]],
    );

    expect($r->fresh()->status)->toBe(RequisitionStatus::FulfilledShort);
});

it('blocks approval when no line is granted a positive quantity', function () {
    $r = draftRequisition($this, 15);
    $r = $this->requisitions->submit($r, $this->actor);
    $lineId = $r->lines()->first()->id;

    expect(fn () => $this->requisitions->approve($r, $this->approver, [$lineId => 0]))
        ->toThrow(App\Domain\Inventory\Exceptions\InventoryException::class);
});

/*
 * ── A branch asking another branch ───────────────────────────────────────────
 *
 * Ashaiman has a surplus, Test Branch needs it, and they are nearer each other
 * than either is to the mother kitchen. A requisition is the right verb: you
 * can only DISPATCH stock you hold, so asking Test Branch to raise a transfer
 * out of Ashaiman's shelf inverts who is doing what.
 *
 * The domain already expected this - `approve()` carries a comment saying the
 * branch manager's approve grant exists "so they can fulfil requests from OTHER
 * branches drawing on their stock". Only `source_type` was hardcoded.
 */
it('lets one branch requisition from another, and labels the source honestly', function () {
    $supplierBranchRow = Branch::factory()->create();
    $supplier = Location::factory()->satellite()->create(['branch_id' => $supplierBranchRow->id]);

    $this->engine->post([
        'item_id' => $this->item->id, 'location_id' => $supplier->id, 'quantity' => 60,
        'movement_type' => 'purchase', 'unit_cost_at_time' => 5.0,
        'idempotency_key' => 'peer-seed',
    ]);

    $r = $this->requisitions->create([
        'requesting_location_id' => $this->branch->id,
        'source_location_id' => $supplier->id,
        'purpose' => 'supplementary',
        'items' => [['item_id' => $this->item->id, 'requested_qty' => 12]],
    ], $this->requester);

    // Was hardcoded 'warehouse', which mislabelled every peer request.
    expect($r->source_type)->toBe('branch');

    // The supplying branch's manager approves - it is their stock going out.
    $supplierManager = User::factory()->create();
    Employee::factory()->create(['user_id' => $supplierManager->id])
        ->branches()->attach($supplierBranchRow->id);

    $r = $this->requisitions->submit($r, $this->requester);
    $r = $this->requisitions->approve($r, $supplierManager);

    expect($r->status)->toBe(RequisitionStatus::Approved);

    $transfer = Transfer::find($r->fulfilling_transfer_id);
    expect($transfer->source_location_id)->toBe($supplier->id)
        ->and($transfer->destination_location_id)->toBe($this->branch->id);
});

it('will not let an unrelated branch sign away another branch stock', function () {
    $supplier = Location::factory()->satellite()->create(['branch_id' => Branch::factory()->create()->id]);
    $this->engine->post([
        'item_id' => $this->item->id, 'location_id' => $supplier->id, 'quantity' => 60,
        'movement_type' => 'purchase', 'unit_cost_at_time' => 5.0,
        'idempotency_key' => 'peer-seed-2',
    ]);

    $r = $this->requisitions->create([
        'requesting_location_id' => $this->branch->id,
        'source_location_id' => $supplier->id,
        'purpose' => 'supplementary',
        'items' => [['item_id' => $this->item->id, 'requested_qty' => 12]],
    ], $this->requester);
    $r = $this->requisitions->submit($r, $this->requester);

    // A manager at some third branch. Not the requester, so the old "cannot
    // approve your own" guard let them straight through - and once a branch can
    // be a source, that means signing away stock that is none of their business.
    $outsiderBranch = Branch::factory()->create();
    $outsider = User::factory()->create();
    Employee::factory()->create(['user_id' => $outsider->id])
        ->branches()->attach($outsiderBranch->id);

    expect(fn () => $this->requisitions->approve($r->fresh(), $outsider))
        ->toThrow(InventoryException::class, 'only someone there can approve it');
});
