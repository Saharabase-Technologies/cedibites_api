<?php

use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Domain\Inventory\Transfers\TransferService;
use App\Enums\Inventory\TransferStatus;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\Inventory\Transfer;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->engine = app(MovementPostingEngine::class);
    $this->service = app(TransferService::class);
    $this->actor = User::factory()->create();

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
