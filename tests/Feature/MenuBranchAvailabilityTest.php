<?php

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * The branch availability matrix.
 *
 * Half of this pins down the thing that broke last time: the old
 * branch-overrides pair resolved a dish at another branch as a sibling row, so
 * once menu:unify merged those siblings it matched nothing — and said nothing.
 * These tests assert the write actually lands on the pivot, because "reported
 * success" was never the part that failed.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

it('returns every branch and every item', function () {
    $branchA = Branch::factory()->create(['name' => 'Ashaiman', 'is_active' => true]);
    $branchB = Branch::factory()->create(['name' => 'Test Branch', 'is_active' => true]);
    $item = MenuItem::factory()->create(['name' => 'Jollof Rice', 'branch_id' => $branchA->id]);
    $item->branches()->attach($branchA->id, ['is_available' => true]);

    $response = $this->actingAs($this->admin)
        ->getJson('/v1/admin/menu-items/branch-availability')
        ->assertSuccessful();

    $data = $response->json('data');

    expect(collect($data['branches'])->pluck('id'))
        ->toContain($branchA->id, $branchB->id);

    $row = collect($data['items'])->firstWhere('id', $item->id);
    expect($row['name'])->toBe('Jollof Rice')
        ->and($row['branches'][(string) $branchA->id])->toBeTrue()
        // Not served at B: absent, rather than present-and-false. "We don't
        // sell this here" and "we're out today" are different statements.
        ->and($row['branches'])->not->toHaveKey((string) $branchB->id);
});

it('starts serving a dish at a branch', function () {
    $branch = Branch::factory()->create(['is_active' => true]);
    $item = MenuItem::factory()->create();

    $this->actingAs($this->admin)
        ->patchJson("/v1/admin/menu-items/{$item->id}/branches/{$branch->id}", ['served' => true])
        ->assertSuccessful();

    expect($item->branches()->where('branches.id', $branch->id)->exists())->toBeTrue();
});

it('stops serving a dish at a branch', function () {
    $branch = Branch::factory()->create(['is_active' => true]);
    $item = MenuItem::factory()->create();
    $item->branches()->attach($branch->id, ['is_available' => true]);

    $this->actingAs($this->admin)
        ->patchJson("/v1/admin/menu-items/{$item->id}/branches/{$branch->id}", ['served' => false])
        ->assertSuccessful();

    expect($item->branches()->where('branches.id', $branch->id)->exists())->toBeFalse();
});

it('does not clear a sold-out flag when re-serving', function () {
    $branch = Branch::factory()->create(['is_active' => true]);
    $item = MenuItem::factory()->create();
    // The branch marked it sold out this morning.
    $item->branches()->attach($branch->id, ['is_available' => false]);

    $this->actingAs($this->admin)
        ->patchJson("/v1/admin/menu-items/{$item->id}/branches/{$branch->id}", ['served' => true])
        ->assertSuccessful();

    // syncWithoutDetaching, so the admin confirming the dish is on the menu
    // does not silently put it back on sale over the branch's head.
    expect((bool) $item->branches()->where('branches.id', $branch->id)->first()->pivot->is_available)
        ->toBeFalse();
});

it('serves a dish everywhere in one call, skipping closed branches', function () {
    Branch::factory()->count(3)->create(['is_active' => true]);
    $inactive = Branch::factory()->create(['is_active' => false]);
    $item = MenuItem::factory()->create();

    // Counted rather than hardcoded: the MenuItem factory brings branches of
    // its own along, so a literal here asserts the fixture, not the behaviour.
    $activeCount = Branch::query()->where('is_active', true)->count();

    $this->actingAs($this->admin)
        ->patchJson("/v1/admin/menu-items/{$item->id}/branches", ['served' => true])
        ->assertSuccessful();

    expect($item->branches()->count())->toBe($activeCount)
        ->and($item->branches()->where('branches.id', $inactive->id)->exists())->toBeFalse();
});

it('refuses a caller without manage_menu', function () {
    $branch = Branch::factory()->create(['is_active' => true]);
    $item = MenuItem::factory()->create();
    $nobody = User::factory()->create();

    $this->actingAs($nobody)
        ->getJson('/v1/admin/menu-items/branch-availability')
        ->assertForbidden();

    $this->actingAs($nobody)
        ->patchJson("/v1/admin/menu-items/{$item->id}/branches/{$branch->id}", ['served' => true])
        ->assertForbidden();
});
