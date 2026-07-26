<?php

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Domain\Inventory\Transfers\TransferService;
use App\Enums\Inventory\TransferStatus;
use App\Enums\Permission;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\Inventory\Transfer;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // VIEW_ALL_FOR_TESTS: outbound acts (submit/approve/send) are gated to the
    // SOURCE location. The warehouse has no branch, so whoever dispatches from
    // it must hold view_all_locations — that is what makes them a warehouse
    // operator rather than branch staff.
    $this->seed(PermissionSeeder::class);
    $this->engine = app(MovementPostingEngine::class);
    $this->service = app(TransferService::class);
    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(Permission::InventoryViewAllLocations->value);

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
        'idempotency_key' => 'seed-transfer',
    ]);
});

function balanceAt(int $itemId, int $locationId): float
{
    $q = DB::table('inventory_stock_balances')
        ->where('item_id', $itemId)->where('location_id', $locationId)->value('quantity');

    return $q !== null ? (float) $q : 0.0;
}

function draftTransfer($test, float $qty): Transfer
{
    return $test->service->create([
        'source_location_id' => $test->warehouse->id,
        'destination_location_id' => $test->branch->id,
        'items' => [['item_id' => $test->item->id, 'requested_qty' => $qty]],
    ], $test->actor);
}

it('moves stock warehouse → branch across the full lifecycle', function () {
    $t = draftTransfer($this, 30);

    $t = $this->service->submit($t, $this->actor);
    expect($t->status)->toBe(TransferStatus::Submitted);

    $t = $this->service->approve($t, $this->actor);
    expect($t->status)->toBe(TransferStatus::Approved);

    $t = $this->service->send($t, $this->actor);
    expect($t->status)->toBe(TransferStatus::Sent)
        ->and(balanceAt($this->item->id, $this->warehouse->id))->toBe(70.0) // 100 - 30 left source
        ->and(balanceAt($this->item->id, $this->branch->id))->toBe(0.0);     // not yet arrived

    $t = $this->service->receive($t, $this->receiver);
    expect($t->fresh()->status)->toBe(TransferStatus::Received)
        ->and(balanceAt($this->item->id, $this->branch->id))->toBe(30.0);    // arrived at branch
});

it('routes a short receipt to disputed and spawns a corrective transfer', function () {
    $t = draftTransfer($this, 30);
    $t = $this->service->submit($t, $this->actor);
    $t = $this->service->approve($t, $this->actor);
    $t = $this->service->send($t, $this->actor);

    $lineId = $t->lines()->first()->id;
    $t = $this->service->receive($t, $this->receiver, [$lineId => 25]); // 5 short

    expect($t->fresh()->status)->toBe(TransferStatus::Disputed)
        ->and(balanceAt($this->item->id, $this->branch->id))->toBe(25.0)
        ->and($t->dispute()->where('status', 'open')->exists())->toBeTrue();

    $t = $this->service->resolveDispute($t, $this->actor, 'Driver confirmed 5 short.');

    expect($t->fresh()->status)->toBe(TransferStatus::ClosedDisputed);

    $corrective = Transfer::where('parent_transfer_id', $t->id)->first();
    expect($corrective)->not->toBeNull()
        ->and($corrective->status)->toBe(TransferStatus::Draft)
        ->and((float) $corrective->lines()->first()->requested_qty)->toBe(5.0);
});

it('blocks submit when the source is short on stock', function () {
    $t = draftTransfer($this, 200); // only 100 on hand

    expect(fn () => $this->service->submit($t, $this->actor))
        ->toThrow(App\Domain\Inventory\Exceptions\InventoryException::class);
});

it('lets an admin override the source-stock check', function () {
    $t = draftTransfer($this, 200);

    $t = $this->service->submit($t, $this->actor, override: true);

    expect($t->status)->toBe(TransferStatus::Submitted)
        ->and($t->source_validation_overridden_by)->toBe($this->actor->id);
});

/*
 * ── The warehouse manager brokering between two branches ─────────────────────
 *
 * Ashaiman has a surplus and Test Branch needs it, and they are nearer each
 * other than either is to the mother kitchen. So the warehouse manager raises
 * the transfer - but he works at neither end, and `operatingLocationIds()`
 * returns warehouses only for him. The draft sat in limbo: he could not submit
 * it, and nobody at Ashaiman had reason to go looking for it.
 *
 * Submitting moves no stock, so the creator may do it from anywhere. Approve and
 * send stay with the source, because those declare goods physically gone.
 */
it('lets the warehouse manager get a branch-to-branch draft into the source queue', function () {
    $branchB = Location::factory()->satellite()->create(['branch_id' => Branch::factory()->create()->id]);
    $branchC = Location::factory()->satellite()->create(['branch_id' => Branch::factory()->create()->id]);

    app(MovementPostingEngine::class)->post([
        'item_id' => $this->item->id, 'location_id' => $branchB->id, 'quantity' => 50,
        'movement_type' => 'purchase', 'unit_cost_at_time' => 3.0,
        'idempotency_key' => 'broker-seed',
    ]);

    // The warehouse manager: sees everywhere, but operates only at warehouses.
    $wm = User::factory()->create();
    $wm->givePermissionTo(Permission::InventoryViewAllLocations->value);
    expect($wm->operatingLocationIds())->not->toContain($branchB->id);

    $transfer = $this->service->create([
        'source_location_id' => $branchB->id,
        'destination_location_id' => $branchC->id,
        'items' => [['item_id' => $this->item->id, 'requested_qty' => 10]],
    ], $wm);

    // He raised it, so he can send it on its way to Ashaiman's queue.
    $transfer = $this->service->submit($transfer, $wm);
    expect($transfer->status)->toBe(TransferStatus::Submitted);

    // But dispatching is still the source's. He cannot approve it...
    expect(fn () => $this->service->approve($transfer->fresh(), $wm))
        ->toThrow(InventoryException::class, 'only someone there can approve it');

    // ...the manager at branch B can, and can send.
    $bManager = User::factory()->create();
    Employee::factory()->create(['user_id' => $bManager->id])
        ->branches()->attach($branchB->branch_id);

    $transfer = $this->service->approve($transfer->fresh(), $bManager);
    $transfer = $this->service->send($transfer->fresh('lines'), $bManager);

    expect($transfer->status)->toBe(TransferStatus::Sent)
        ->and(balanceAt($this->item->id, $branchB->id))->toBe(40.0);
});

it('will not let the warehouse manager declare that a branch shipped', function () {
    $branchB = Location::factory()->satellite()->create(['branch_id' => Branch::factory()->create()->id]);
    $branchC = Location::factory()->satellite()->create(['branch_id' => Branch::factory()->create()->id]);

    app(MovementPostingEngine::class)->post([
        'item_id' => $this->item->id, 'location_id' => $branchB->id, 'quantity' => 50,
        'movement_type' => 'purchase', 'unit_cost_at_time' => 3.0,
        'idempotency_key' => 'broker-seed-2',
    ]);

    $wm = User::factory()->create();
    $wm->givePermissionTo(Permission::InventoryViewAllLocations->value);

    $transfer = $this->service->create([
        'source_location_id' => $branchB->id,
        'destination_location_id' => $branchC->id,
        'items' => [['item_id' => $this->item->id, 'requested_qty' => 10]],
    ], $wm);
    $transfer = $this->service->submit($transfer, $wm);

    $bManager = User::factory()->create();
    Employee::factory()->create(['user_id' => $bManager->id])
        ->branches()->attach($branchB->branch_id);
    $transfer = $this->service->approve($transfer->fresh(), $bManager);

    // Marking it sent from the mother kitchen would say goods left a building
    // he is not standing in. Stock must not move on that say-so.
    expect(fn () => $this->service->send($transfer->fresh('lines'), $wm))
        ->toThrow(InventoryException::class, 'only someone there can send it');

    expect(balanceAt($this->item->id, $branchB->id))->toBe(50.0);
});
