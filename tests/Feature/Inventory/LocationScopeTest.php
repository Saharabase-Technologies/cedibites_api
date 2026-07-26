<?php

use App\Enums\Inventory\TransferStatus;
use App\Enums\Permission;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\Inventory\Transfer;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

/**
 * A branch manager's inventory reads must be confined to their own branch.
 * Anyone holding `inventory.view_all_locations` (admin, warehouse manager,
 * purchasing clerk) is deliberately exempt.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->warehouse = Location::factory()->warehouse()->create(['branch_id' => null]);

    // Two branches, each with its own inventory location.
    $this->ownBranch = Branch::factory()->create();
    $this->otherBranch = Branch::factory()->create();

    $this->ownLocation = Location::factory()->satellite()->create([
        'branch_id' => $this->ownBranch->id,
    ]);
    $this->otherLocation = Location::factory()->satellite()->create([
        'branch_id' => $this->otherBranch->id,
    ]);

    // Branch manager assigned to ownBranch only.
    $this->bm = User::factory()->create();
    Employee::factory()->create(['user_id' => $this->bm->id])
        ->branches()->attach($this->ownBranch->id);
    $this->bm->givePermissionTo(Permission::ViewInventoryCatalog->value);

    // Warehouse-level user — sees everything.
    $this->wm = User::factory()->create();
    $this->wm->givePermissionTo([
        Permission::ViewInventoryCatalog->value,
        Permission::InventoryViewAllLocations->value,
    ]);
});

/** There is no Transfer factory — the suite builds them through the service. */
function transferBetween(int $from, int $to): Transfer
{
    static $n = 0;

    return Transfer::create([
        'reference' => 'TRF-SCOPE-'.str_pad((string) ++$n, 3, '0', STR_PAD_LEFT),
        'source_location_id' => $from,
        'destination_location_id' => $to,
        'status' => TransferStatus::Draft,
        'created_by' => User::factory()->create()->id,
    ]);
}

it('hides transfers that never touch the branch manager\'s location', function () {
    $mine = transferBetween($this->warehouse->id, $this->ownLocation->id);
    $theirs = transferBetween($this->warehouse->id, $this->otherLocation->id);

    $ids = collect(
        $this->actingAs($this->bm)
            ->getJson('/v1/inventory/transfers')
            ->assertSuccessful()
            ->json('data')
    )->pluck('id');

    expect($ids)->toContain($mine->id)
        ->and($ids)->not->toContain($theirs->id);
});

it('shows a transfer from either end — inbound and outbound both count', function () {
    $inbound = transferBetween($this->warehouse->id, $this->ownLocation->id);
    $outbound = transferBetween($this->ownLocation->id, $this->warehouse->id);

    $ids = collect(
        $this->actingAs($this->bm)
            ->getJson('/v1/inventory/transfers')
            ->assertSuccessful()
            ->json('data')
    )->pluck('id');

    expect($ids)->toContain($inbound->id)
        ->and($ids)->toContain($outbound->id);
});

it('404s a direct fetch of an out-of-scope transfer rather than confirming it exists', function () {
    $theirs = transferBetween($this->warehouse->id, $this->otherLocation->id);

    $this->actingAs($this->bm)
        ->getJson("/v1/inventory/transfers/{$theirs->id}")
        ->assertNotFound();
});

it('still serves an in-scope transfer directly', function () {
    $mine = transferBetween($this->warehouse->id, $this->ownLocation->id);

    $this->actingAs($this->bm)
        ->getJson("/v1/inventory/transfers/{$mine->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $mine->id);
});

it('exempts a holder of inventory.view_all_locations from scoping', function () {
    $mine = transferBetween($this->warehouse->id, $this->ownLocation->id);
    $theirs = transferBetween($this->warehouse->id, $this->otherLocation->id);

    $ids = collect(
        $this->actingAs($this->wm)
            ->getJson('/v1/inventory/transfers')
            ->assertSuccessful()
            ->json('data')
    )->pluck('id');

    expect($ids)->toContain($mine->id)
        ->and($ids)->toContain($theirs->id);
});

it('shows nothing to a scoped user with no branch assignment', function () {
    transferBetween($this->warehouse->id, $this->ownLocation->id);

    $orphan = User::factory()->create();
    $orphan->givePermissionTo(Permission::ViewInventoryCatalog->value);

    $this->actingAs($orphan)
        ->getJson('/v1/inventory/transfers')
        ->assertSuccessful()
        ->assertJsonCount(0, 'data');
});

/*
|--------------------------------------------------------------------------
| Requisition creation must not outrun the read scope
|--------------------------------------------------------------------------
|
| Creation once accepted any location that merely existed, while `show()`
| enforced the location scope — so a branch manager could file a requisition and
| be met with a 404 on the record they had just created.
|
*/

/** @return array<string, mixed> */
function requisitionPayload(int $sourceId, int $itemId, ?int $requestingId = null): array
{
    return array_filter([
        'requesting_location_id' => $requestingId,
        'source_location_id' => $sourceId,
        'purpose' => 'supplementary',
        'items' => [['item_id' => $itemId, 'requested_qty' => 5]],
    ], fn ($v) => $v !== null);
}

it('lets a branch manager read back the requisition they just created', function () {
    $this->bm->givePermissionTo(Permission::InventoryRequisitionCreate->value);
    $item = Item::factory()->create();

    // No requesting_location_id — the manager's own branch is implied.
    $id = $this->actingAs($this->bm)
        ->postJson('/v1/inventory/requisitions', requisitionPayload($this->warehouse->id, $item->id))
        ->assertSuccessful()
        ->json('data.id');

    $this->actingAs($this->bm)
        ->getJson("/v1/inventory/requisitions/{$id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.requesting_location.id', $this->ownLocation->id);
});

it('refuses a requisition raised against a branch the manager does not run', function () {
    $this->bm->givePermissionTo(Permission::InventoryRequisitionCreate->value);
    $item = Item::factory()->create();

    $this->actingAs($this->bm)
        ->postJson('/v1/inventory/requisitions', requisitionPayload(
            $this->warehouse->id,
            $item->id,
            $this->otherLocation->id,
        ))
        ->assertStatus(422)
        ->assertJsonPath('message', 'You can only raise requisitions for your own branch.');
});

it('tells a manager whose branch has no inventory location why they cannot requisition', function () {
    $item = Item::factory()->create();

    // Assigned to a branch, but nothing provisioned an inventory location for it
    // — the state a branch created after the IMS seeder ran lands in.
    $stranded = User::factory()->create();
    Employee::factory()->create(['user_id' => $stranded->id])
        ->branches()->attach(Branch::factory()->create()->id);
    $stranded->givePermissionTo([
        Permission::ViewInventoryCatalog->value,
        Permission::InventoryRequisitionCreate->value,
    ]);

    $this->actingAs($stranded)
        ->postJson('/v1/inventory/requisitions', requisitionPayload($this->warehouse->id, $item->id))
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'not linked to an inventory location'));
});

it('offers a branch manager only their own location plus warehouses to pick from', function () {
    $ids = collect(
        $this->actingAs($this->bm)
            ->getJson('/v1/inventory/locations')
            ->assertSuccessful()
            ->json('data')
    )->pluck('id');

    expect($ids)->toContain($this->ownLocation->id)
        ->and($ids)->toContain($this->warehouse->id)
        ->and($ids)->not->toContain($this->otherLocation->id);
});

it('reports a branch manager only their own stock, not the warehouse total', function () {
    $item = Item::factory()->create();
    $engine = app(\App\Domain\Inventory\Movements\Engines\MovementPostingEngine::class);

    foreach ([[$this->warehouse->id, 100, 'w'], [$this->ownLocation->id, 7, 'o'], [$this->otherLocation->id, 55, 'x']] as [$loc, $qty, $k]) {
        $engine->post([
            'item_id' => $item->id, 'location_id' => $loc, 'quantity' => $qty,
            'movement_type' => 'purchase', 'unit_cost_at_time' => 1.0,
            'idempotency_key' => "seed-scope-{$k}",
        ]);
    }

    $mine = collect($this->actingAs($this->bm)->getJson('/v1/inventory/items')->assertSuccessful()->json('data'))
        ->firstWhere('id', $item->id);
    $all = collect($this->actingAs($this->wm)->getJson('/v1/inventory/items')->assertSuccessful()->json('data'))
        ->firstWhere('id', $item->id);

    // 7 at their branch — not 162, and not the warehouse's 100.
    expect((float) $mine['stock_on_hand'])->toBe(7.0)
        ->and((float) $all['stock_on_hand'])->toBe(162.0);
});

it('shows the same scoped figure on the item detail as on the list', function () {
    $item = Item::factory()->create();
    $engine = app(\App\Domain\Inventory\Movements\Engines\MovementPostingEngine::class);

    foreach ([[$this->warehouse->id, 100, 'dw'], [$this->ownLocation->id, 10, 'do']] as [$loc, $qty, $k]) {
        $engine->post([
            'item_id' => $item->id, 'location_id' => $loc, 'quantity' => $qty,
            'movement_type' => 'purchase', 'unit_cost_at_time' => 1.0,
            'idempotency_key' => "seed-detail-{$k}",
        ]);
    }

    // The list said 10; opening the item used to show the warehouse's 100.
    $detail = $this->actingAs($this->bm)
        ->getJson("/v1/inventory/items/{$item->id}")
        ->assertSuccessful()
        ->json('data');

    expect((float) $detail['stock_on_hand'])->toBe(10.0);

    // The movement ledger behind it is scoped too — a running balance built from
    // warehouse movements would be nonsense at a branch.
    $movements = $this->actingAs($this->bm)
        ->getJson("/v1/inventory/items/{$item->id}/movements")
        ->assertSuccessful()
        ->json('data');

    expect((float) $movements['item']['stock_on_hand'])->toBe(10.0)
        ->and(collect($movements['movements'])->pluck('location.id')->unique()->all())
        ->toBe([$this->ownLocation->id]);
});

it('keeps the full catalog available so a branch can request what it lacks', function () {
    $held = Item::factory()->create();
    $notHeld = Item::factory()->create();

    app(\App\Domain\Inventory\Movements\Engines\MovementPostingEngine::class)->post([
        'item_id' => $held->id, 'location_id' => $this->ownLocation->id, 'quantity' => 3,
        'movement_type' => 'purchase', 'unit_cost_at_time' => 1.0, 'idempotency_key' => 'seed-held',
    ]);

    $all = collect($this->actingAs($this->bm)->getJson('/v1/inventory/items')->json('data'))->pluck('id');
    $onlyStocked = collect($this->actingAs($this->bm)->getJson('/v1/inventory/items?in_stock_only=1')->json('data'))->pluck('id');

    expect($all)->toContain($held->id)
        ->and($all)->toContain($notHeld->id)      // requestable
        ->and($onlyStocked)->toContain($held->id)
        ->and($onlyStocked)->not->toContain($notHeld->id);
});

it('still lets an unrestricted user raise a requisition for any branch', function () {
    $this->wm->givePermissionTo(Permission::InventoryRequisitionCreate->value);
    $item = Item::factory()->create();

    $this->actingAs($this->wm)
        ->postJson('/v1/inventory/requisitions', requisitionPayload(
            $this->warehouse->id,
            $item->id,
            $this->otherLocation->id,
        ))
        ->assertSuccessful()
        ->assertJsonPath('data.requesting_location.id', $this->otherLocation->id);
});
