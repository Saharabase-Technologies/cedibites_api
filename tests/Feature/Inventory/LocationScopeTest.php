<?php

use App\Enums\Inventory\TransferStatus;
use App\Enums\Permission;
use App\Models\Branch;
use App\Models\Employee;
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
