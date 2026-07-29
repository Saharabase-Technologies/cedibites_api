<?php

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The two availability flags, and the rule that they are not the same flag.
 *
 *   menu_items.is_available          on sale company-wide — the admin's
 *   menu_item_branches.is_available  we have it here today — the branch's
 *
 * `menu:unify` seeded the second from the first, which stamped a permanent
 * "sold out" on every branch row of a dish that happened to be withdrawn when
 * the merge ran. Nothing else writes that column, so putting the dish back on
 * sale company-wide never cleared it and whole branch menus read "not
 * available" with no way back.
 *
 * The other half: the flag was written and never read by anything that sells.
 * A manager marking Jollof sold out changed a row and nothing else — the till
 * kept offering it.
 */
/**
 * A real bearer token carrying the `staff` ability, rather than actingAs().
 * EnsureStaffToken reads `$token->abilities` directly, and both actingAs() and
 * Sanctum::actingAs() leave a transient token that has none — so the admin
 * surface is only reachable in a test through a token that was actually minted.
 */
function staffToken(User $user): string
{
    return $user->createToken('test', ['staff'])->plainTextToken;
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

it('does not stamp the branch flag from the company-wide one', function () {
    $branch = Branch::factory()->create(['is_active' => true]);
    // Withdrawn company-wide — which says nothing about whether the branch has
    // it, and must not be copied onto the branch's own row.
    $item = MenuItem::factory()->create(['branch_id' => $branch->id, 'is_available' => false]);

    $this->artisan('menu:unify')->assertSuccessful();

    $pivot = DB::table('menu_item_branches')
        ->where('menu_item_id', $item->id)
        ->where('branch_id', $branch->id)
        ->first();

    expect($pivot)->not->toBeNull()
        ->and((bool) $pivot->is_available)->toBeTrue();
});

it('leaves a branch sold-out flag alone when it runs again', function () {
    $branch = Branch::factory()->create(['is_active' => true]);
    $item = MenuItem::factory()->create(['branch_id' => $branch->id]);
    // The branch ran out this morning.
    $item->branches()->attach($branch->id, ['is_available' => false]);

    // menu:unify runs on every deploy. A deploy must not put the branch's menu
    // back on over its head.
    $this->artisan('menu:unify')->assertSuccessful();

    expect((bool) $item->branches()->where('branches.id', $branch->id)->first()->pivot->is_available)
        ->toBeFalse();
});

it('keeps a dish a branch has sold out off that branch till', function () {
    $branch = Branch::factory()->create(['is_active' => true]);
    $onSale = MenuItem::factory()->create(['name' => 'Waakye', 'is_available' => true]);
    $soldOut = MenuItem::factory()->create(['name' => 'Jollof Rice', 'is_available' => true]);

    $onSale->branches()->attach($branch->id, ['is_available' => true]);
    $soldOut->branches()->attach($branch->id, ['is_available' => false]);

    $names = collect(
        $this->getJson("/v1/menu-items?branch_id={$branch->id}&is_available=1")
            ->assertSuccessful()
            ->json('data')
    )->pluck('name');

    expect($names)->toContain('Waakye')
        ->and($names)->not->toContain('Jollof Rice');
});

/**
 * The manager's own screen has to list the sold-out ones — it is where they get
 * put back. Which is why this is a separate scope rather than a change to
 * servedAt.
 */
it('still lists a sold-out dish as served at the branch', function () {
    $branch = Branch::factory()->create(['is_active' => true]);
    $item = MenuItem::factory()->create(['name' => 'Jollof Rice']);
    $item->branches()->attach($branch->id, ['is_available' => false]);

    expect(MenuItem::query()->servedAt($branch->id)->pluck('name'))->toContain('Jollof Rice')
        ->and(MenuItem::query()->onSaleAt($branch->id)->pluck('name'))->not->toContain('Jollof Rice');
});

/**
 * No verdict never means refuse — the same rule the stock gate follows. An item
 * the merge has not reached has no pivot row and therefore no branch flag; it
 * must fall through on its legacy branch_id rather than vanish.
 */
it('sells a dish that has no pivot row yet', function () {
    $branch = Branch::factory()->create(['is_active' => true]);
    $item = MenuItem::factory()->create(['branch_id' => $branch->id, 'is_available' => true]);
    $item->branches()->detach();

    expect(MenuItem::query()->onSaleAt($branch->id)->pluck('id'))->toContain($item->id);
});

it('puts back only the rows no one has touched since the merge', function () {
    $branch = Branch::factory()->create(['is_active' => true]);
    $stamped = MenuItem::factory()->create(['name' => 'Stamped by the merge']);
    $byHand = MenuItem::factory()->create(['name' => 'Sold out this morning']);

    $stamped->branches()->attach($branch->id, ['is_available' => false]);
    $byHand->branches()->attach($branch->id, ['is_available' => false]);

    // The merge wrote created_at and updated_at in one statement; a manager's
    // toggle moves updated_at alone. That is the whole discriminator.
    DB::table('menu_item_branches')
        ->where('menu_item_id', $stamped->id)
        ->update(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
    DB::table('menu_item_branches')
        ->where('menu_item_id', $byHand->id)
        ->update(['created_at' => now()->subDays(2), 'updated_at' => now()]);

    $this->artisan('menu:repair-branch-availability')->assertSuccessful();

    expect((bool) $stamped->branches()->first()->pivot->is_available)->toBeTrue()
        ->and((bool) $byHand->branches()->first()->pivot->is_available)->toBeFalse();
});

/**
 * Leaving the flag alone unless asked is right; leaving it alone with no way to
 * ask was the bug. `served: true` used to be the only verb, and it went through
 * syncWithoutDetaching with a bare id array — which carries no pivot
 * attributes, so sync never touched the existing row. A branch stuck sold out
 * could not be put back on by anyone.
 */
it('puts a sold-out dish back on when the admin asks for it', function () {
    $branch = Branch::factory()->create(['is_active' => true]);
    $item = MenuItem::factory()->create();
    $item->branches()->attach($branch->id, ['is_available' => false]);

    $this->withToken(staffToken($this->admin))
        ->patchJson("/v1/admin/menu-items/{$item->id}/branches/{$branch->id}", ['available' => true])
        ->assertSuccessful();

    expect((bool) $item->branches()->where('branches.id', $branch->id)->first()->pivot->is_available)
        ->toBeTrue();
});

it('still leaves a sold-out flag alone when only re-serving', function () {
    $branch = Branch::factory()->create(['is_active' => true]);
    $item = MenuItem::factory()->create();
    $item->branches()->attach($branch->id, ['is_available' => false]);

    $this->withToken(staffToken($this->admin))
        ->patchJson("/v1/admin/menu-items/{$item->id}/branches/{$branch->id}", ['served' => true])
        ->assertSuccessful();

    // Confirming a dish is on the menu is not the same as overruling a branch
    // that ran out this morning.
    expect((bool) $item->branches()->where('branches.id', $branch->id)->first()->pivot->is_available)
        ->toBeFalse();
});

it('marks a dish sold out at one branch without touching the others', function () {
    $here = Branch::factory()->create(['is_active' => true]);
    $elsewhere = Branch::factory()->create(['is_active' => true]);
    $item = MenuItem::factory()->create();
    $item->branches()->attach([$here->id, $elsewhere->id], ['is_available' => true]);

    $this->withToken(staffToken($this->admin))
        ->patchJson("/v1/admin/menu-items/{$item->id}/branches/{$here->id}", ['available' => false])
        ->assertSuccessful();

    expect((bool) $item->branches()->where('branches.id', $here->id)->first()->pivot->is_available)
        ->toBeFalse()
        ->and((bool) $item->branches()->where('branches.id', $elsewhere->id)->first()->pivot->is_available)
        ->toBeTrue();
});

it('refuses a change that says nothing', function () {
    $branch = Branch::factory()->create(['is_active' => true]);
    $item = MenuItem::factory()->create();

    $this->withToken(staffToken($this->admin))
        ->patchJson("/v1/admin/menu-items/{$item->id}/branches/{$branch->id}", [])
        ->assertStatus(422);
});

it('writes nothing on a dry run', function () {
    $branch = Branch::factory()->create(['is_active' => true]);
    $item = MenuItem::factory()->create();
    $item->branches()->attach($branch->id, ['is_available' => false]);
    DB::table('menu_item_branches')->update(['created_at' => now(), 'updated_at' => now()]);

    $this->artisan('menu:repair-branch-availability', ['--dry-run' => true])->assertSuccessful();

    expect((bool) $item->branches()->first()->pivot->is_available)->toBeFalse();
});
