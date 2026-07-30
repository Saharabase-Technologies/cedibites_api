<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
|--------------------------------------------------------------------------
| Ordering agrees with the menu it was shown
|--------------------------------------------------------------------------
|
| A dish used to be a separate row per branch. After `menu:unify` it is one row
| sold at many branches, and `menu_items.branch_id` holds only whichever branch
| it happened to be consolidated onto.
|
| Every listing path moved to servedAt/onSaleAt. The two order paths did not —
| they kept asking `$item->branch_id !== $branchId`. So the branch that won the
| merge could sell a dish and every other branch was told, at the moment of
| paying, that a dish on its own menu "is not available at this branch".
|
| The reverse matters as much: a dish the branch has marked sold out was hidden
| from the till but nothing refused an order for it.
|
*/

/**
 * @return array{user: User, employee: Employee}
 */
function menuStaff(Branch $branch): array
{
    $user = User::factory()->create();
    $employee = Employee::factory()->create([
        'user_id' => $user->id,
        'status' => EmployeeStatus::Active,
    ]);
    $employee->branches()->attach($branch);
    $user->syncRoles([RoleEnum::SalesStaff->value]);

    return ['user' => $user->fresh(), 'employee' => $employee];
}

/** Open a checkout session for one portion of $dish at $branch. */
function orderDish(MenuItem $dish, Branch $branch)
{
    return test()->postJson('/v1/pos/checkout-sessions', [
        'branch_id' => $branch->id,
        'items' => [[
            'menu_item_id' => $dish->id,
            'menu_item_option_id' => $dish->options->first()->id,
            'quantity' => 1,
            'unit_price' => 20,
        ]],
        'payment_method' => 'cash',
        'fulfillment_type' => 'takeaway',
        'contact_name' => 'Walk-in',
        'contact_phone' => '+233541234567',
    ]);
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->mergedOnto = Branch::factory()->create([
        'is_active' => true,
        // Otherwise these pass or fail depending on the time of day: posStore
        // refuses an order at a closed branch, and the factory's operating hours
        // are real ones.
        'extended_staff_access' => true,
        'extended_order_access' => true,
    ]);
    $this->otherBranch = Branch::factory()->create([
        'is_active' => true,
        // Otherwise these pass or fail depending on the time of day: posStore
        // refuses an order at a closed branch, and the factory's operating hours
        // are real ones.
        'extended_staff_access' => true,
        'extended_order_access' => true,
    ]);
});

/**
 * The reported symptom, exactly: a unified dish, ordered at a branch that is
 * not the one its legacy column points at.
 */
it('sells a unified dish at a branch other than the one it was merged onto', function () {
    $dish = MenuItem::factory()->create([
        'name' => 'Banku With Grilled Tilapia',
        'branch_id' => $this->mergedOnto->id,
        'is_available' => true,
    ]);
    $dish->branches()->attach($this->mergedOnto->id, ['is_available' => true]);
    $dish->branches()->attach($this->otherBranch->id, ['is_available' => true]);
    $dish = $dish->fresh(['options']);

    ['user' => $cashier] = menuStaff($this->otherBranch);

    $this->actingAs($cashier);
    orderDish($dish, $this->otherBranch)->assertSuccessful();
});

it('sells it at the branch it was merged onto too', function () {
    $dish = MenuItem::factory()->create(['branch_id' => $this->mergedOnto->id, 'is_available' => true]);
    $dish->branches()->attach($this->mergedOnto->id, ['is_available' => true]);
    $dish = $dish->fresh(['options']);

    ['user' => $cashier] = menuStaff($this->mergedOnto);

    $this->actingAs($cashier);
    orderDish($dish, $this->mergedOnto)->assertSuccessful();
});

it('refuses a dish the branch does not serve at all', function () {
    $dish = MenuItem::factory()->create([
        'name' => 'Banku With Grilled Tilapia',
        'branch_id' => $this->mergedOnto->id,
        'is_available' => true,
    ]);
    $dish->branches()->attach($this->mergedOnto->id, ['is_available' => true]);
    $dish = $dish->fresh(['options']);

    ['user' => $cashier] = menuStaff($this->otherBranch);

    $this->actingAs($cashier);
    orderDish($dish, $this->otherBranch)
        ->assertStatus(422)
        ->assertJsonFragment([
            'message' => 'Menu item Banku With Grilled Tilapia is not available at this branch',
        ]);
});

/**
 * The manager's sold-out toggle hid the dish from the till and nothing else.
 * An order arriving by any other route went straight through.
 */
it('refuses a dish the branch has marked sold out', function () {
    $dish = MenuItem::factory()->create(['name' => 'Jollof Rice', 'is_available' => true]);
    $dish->branches()->attach($this->otherBranch->id, ['is_available' => false]);
    $dish = $dish->fresh(['options']);

    ['user' => $cashier] = menuStaff($this->otherBranch);

    $this->actingAs($cashier);
    orderDish($dish, $this->otherBranch)
        ->assertStatus(422)
        ->assertJsonFragment([
            'message' => 'Menu item Jollof Rice is not available at this branch',
        ]);
});

/**
 * No verdict never means refuse. A dish the merge has not reached has no pivot
 * row and must still sell on its legacy branch_id, or deploying ahead of the
 * data empties every till in the business.
 */
it('still sells a dish the merge has not reached', function () {
    $dish = MenuItem::factory()->create(['branch_id' => $this->otherBranch->id, 'is_available' => true]);
    $dish->branches()->detach();
    $dish = $dish->fresh(['options']);

    ['user' => $cashier] = menuStaff($this->otherBranch);

    $this->actingAs($cashier);
    orderDish($dish, $this->otherBranch)->assertSuccessful();
});

it('refuses a dish taken off the menu company-wide', function () {
    $dish = MenuItem::factory()->create(['name' => 'Retired Dish', 'is_available' => false]);
    $dish->branches()->attach($this->otherBranch->id, ['is_available' => true]);
    $dish = $dish->fresh(['options']);

    ['user' => $cashier] = menuStaff($this->otherBranch);

    $this->actingAs($cashier);
    orderDish($dish, $this->otherBranch)->assertStatus(422);
});

it('names the offending dish when only one of several is unavailable', function () {
    $ok = MenuItem::factory()->create(['name' => 'Waakye', 'is_available' => true]);
    $ok->branches()->attach($this->otherBranch->id, ['is_available' => true]);

    $bad = MenuItem::factory()->create(['name' => 'Banku With Grilled Tilapia', 'is_available' => true]);
    $bad->branches()->attach($this->mergedOnto->id, ['is_available' => true]);

    $ok = $ok->fresh(['options']);
    $bad = $bad->fresh(['options']);

    ['user' => $cashier] = menuStaff($this->otherBranch);

    $this->actingAs($cashier)
        ->postJson('/v1/pos/checkout-sessions', [
            'branch_id' => $this->otherBranch->id,
            'items' => [
                [
                    'menu_item_id' => $ok->id,
                    'menu_item_option_id' => $ok->options->first()->id,
                    'quantity' => 1,
                    'unit_price' => 20,
                ],
                [
                    'menu_item_id' => $bad->id,
                    'menu_item_option_id' => $bad->options->first()->id,
                    'quantity' => 1,
                    'unit_price' => 30,
                ],
            ],
            'payment_method' => 'cash',
            'fulfillment_type' => 'takeaway',
            'contact_name' => 'Walk-in',
            'contact_phone' => '+233541234567',
        ])
        ->assertStatus(422)
        ->assertJsonFragment([
            'message' => 'Menu item Banku With Grilled Tilapia is not available at this branch',
        ]);
});

/*
|--------------------------------------------------------------------------
| A branch's categories are the categories of what it serves
|--------------------------------------------------------------------------
|
| menu_items was unified; menu_categories still carries the old per-branch
| branch_id. Filtering categories on it returned nothing for any branch never
| given its own rows, so the till showed every dish under a single "All" tab
| with no way to narrow them.
|
*/

it('lists the categories of the dishes a branch serves', function () {
    $category = \App\Models\MenuCategory::factory()->create([
        'name' => 'Rice Dishes',
        'branch_id' => $this->mergedOnto->id,
        'is_active' => true,
    ]);

    $dish = MenuItem::factory()->create([
        'category_id' => $category->id,
        'branch_id' => $this->mergedOnto->id,
        'is_available' => true,
    ]);
    $dish->branches()->attach($this->otherBranch->id, ['is_available' => true]);

    $names = collect(
        $this->getJson("/v1/menu-categories?branch_id={$this->otherBranch->id}&is_active=1")
            ->assertSuccessful()
            ->json('data')
    )->pluck('name');

    expect($names)->toContain('Rice Dishes');
});

it('does not list a category no dish at that branch belongs to', function () {
    $category = \App\Models\MenuCategory::factory()->create([
        'name' => 'Only Elsewhere',
        'branch_id' => $this->mergedOnto->id,
        'is_active' => true,
    ]);

    $dish = MenuItem::factory()->create([
        'category_id' => $category->id,
        'branch_id' => $this->mergedOnto->id,
    ]);
    $dish->branches()->attach($this->mergedOnto->id, ['is_available' => true]);

    $names = collect(
        $this->getJson("/v1/menu-categories?branch_id={$this->otherBranch->id}&is_active=1")
            ->assertSuccessful()
            ->json('data')
    )->pluck('name');

    expect($names)->not->toContain('Only Elsewhere');
});
