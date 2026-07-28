<?php

use App\Domain\Inventory\Stock\StockAvailabilityService;
use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\MenuItem;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Branch Isolation, Phase 4 — no stock, no sale
|--------------------------------------------------------------------------
|
| Deduction used to be fire-and-forget: the balance went negative, an alert was
| raised, and the sale went through. This is the check that stops it.
|
| Half of these tests are about what it must NOT block. Coupling the till to
| the accuracy of the stock ledger means that when the ledger is wrong the POS
| refuses food that is physically on the shelf, in front of a customer. A
| configuration gap must never read as "refuse".
|
*/

/**
 * @return array{user: User, employee: Employee}
 */
function gateStaff(string $role, Branch $branch): array
{
    $user = User::factory()->create();
    $employee = Employee::factory()->create([
        'user_id' => $user->id,
        'status' => EmployeeStatus::Active,
    ]);
    $employee->branches()->attach($branch);
    $user->assignRole($role);

    return ['user' => $user->fresh(), 'employee' => $employee];
}

/**
 * A dish whose recipe needs $qty of $item per portion.
 */
function dishNeeding(Branch $branch, string $name, Item $item, float $qty): MenuItem
{
    $dish = MenuItem::factory()->create(['branch_id' => $branch->id, 'name' => $name]);
    $dish->branches()->attach($branch->id, ['is_available' => true]);

    $option = $dish->options->first();

    $recipeId = DB::table('inventory_recipes')->insertGetId([
        'menu_item_option_id' => $option->id,
        'branch_id' => null,
        'is_default' => true,
        'status' => 'locked',
        'version' => 1,
        'yield_qty' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('inventory_recipe_ingredients')->insert([
        'recipe_id' => $recipeId,
        'item_id' => $item->id,
        // Ingredients are measured in the item's own base unit here; the
        // service compares against the balance, which is held in the same.
        'unit_id' => $item->base_unit_id,
        'quantity' => $qty,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $dish->fresh(['options']);
}

function stockOf(Item $item, Location $location, float $qty): void
{
    DB::table('inventory_stock_balances')->updateOrInsert(
        ['item_id' => $item->id, 'location_id' => $location->id],
        ['quantity' => $qty, 'weighted_avg_cost' => 1, 'updated_at' => now()],
    );
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->create(['name' => 'Ashaiman']);
    $this->location = Location::factory()->create([
        'branch_id' => $this->branch->id,
        'type' => 'satellite',
        'is_active' => true,
    ]);
    $this->chicken = Item::factory()->create(['name' => 'Chicken']);
});

describe('judging a basket', function () {
    it('passes when there is enough', function () {
        $dish = dishNeeding($this->branch, 'Jollof', $this->chicken, 1);
        stockOf($this->chicken, $this->location, 10);

        $result = app(StockAvailabilityService::class)->check($this->branch->id, [
            ['option_id' => $dish->options->first()->id, 'quantity' => 3],
        ]);

        expect($result->canSell())->toBeTrue()
            ->and($result->judged)->toBeTrue();
    });

    it('refuses when there is not, and names the ingredient', function () {
        $dish = dishNeeding($this->branch, 'Jollof', $this->chicken, 1);
        stockOf($this->chicken, $this->location, 2);

        $result = app(StockAvailabilityService::class)->check($this->branch->id, [
            ['option_id' => $dish->options->first()->id, 'quantity' => 5],
        ]);

        expect($result->canSell())->toBeFalse()
            ->and($result->shortfalls[0]['item_name'])->toBe('Chicken')
            ->and($result->message())->toContain('Chicken')
            // "Out of stock" tells a cashier nothing they can act on.
            ->and($result->message())->not->toContain('out of stock');
    });

    it('adds up two dishes sharing an ingredient', function () {
        $a = dishNeeding($this->branch, 'Jollof', $this->chicken, 2);
        $b = dishNeeding($this->branch, 'Fried Rice', $this->chicken, 2);
        stockOf($this->chicken, $this->location, 3);

        $service = app(StockAvailabilityService::class);

        // Either alone fits inside 3.
        expect($service->check($this->branch->id, [['option_id' => $a->options->first()->id, 'quantity' => 1]])->canSell())->toBeTrue()
            ->and($service->check($this->branch->id, [['option_id' => $b->options->first()->id, 'quantity' => 1]])->canSell())->toBeTrue();

        // Together they need 4.
        $both = $service->check($this->branch->id, [
            ['option_id' => $a->options->first()->id, 'quantity' => 1],
            ['option_id' => $b->options->first()->id, 'quantity' => 1],
        ]);

        expect($both->canSell())->toBeFalse();
    });

    it('reads the balance at this branch, not another', function () {
        $elsewhere = Location::factory()->create(['type' => 'warehouse', 'branch_id' => null, 'is_active' => true]);
        $dish = dishNeeding($this->branch, 'Jollof', $this->chicken, 1);

        stockOf($this->chicken, $elsewhere, 100);
        stockOf($this->chicken, $this->location, 0);

        $result = app(StockAvailabilityService::class)->check($this->branch->id, [
            ['option_id' => $dish->options->first()->id, 'quantity' => 1],
        ]);

        expect($result->canSell())->toBeFalse();
    });
});

describe('what it must never block', function () {
    it('lets the sale through when the branch has no inventory location', function () {
        $unwired = Branch::factory()->create();
        $dish = dishNeeding($unwired, 'Jollof', $this->chicken, 1);

        $result = app(StockAvailabilityService::class)->check($unwired->id, [
            ['option_id' => $dish->options->first()->id, 'quantity' => 99],
        ]);

        expect($result->canSell())->toBeTrue()
            ->and($result->judged)->toBeFalse();
    });

    it('lets the sale through when the dish has no recipe', function () {
        $dish = MenuItem::factory()->create(['branch_id' => $this->branch->id]);

        $result = app(StockAvailabilityService::class)->check($this->branch->id, [
            ['option_id' => $dish->options->first()->id, 'quantity' => 99],
        ]);

        expect($result->canSell())->toBeTrue()
            ->and($result->judged)->toBeFalse();
    });

    it('ignores lines with no option, so add-ons never block', function () {
        $result = app(StockAvailabilityService::class)->check($this->branch->id, [
            ['option_id' => 0, 'quantity' => 5],
        ]);

        expect($result->canSell())->toBeTrue();
    });
});

describe('the till', function () {
    it('refuses the order and says what is short', function () {
        $dish = dishNeeding($this->branch, 'Jollof', $this->chicken, 1);
        stockOf($this->chicken, $this->location, 1);
        ['user' => $cashier] = gateStaff(RoleEnum::SalesStaff->value, $this->branch);

        $response = $this->actingAs($cashier)
            ->postJson('/v1/pos/orders', [
                'branch_id' => $this->branch->id,
                'items' => [[
                    'menu_item_id' => $dish->id,
                    'menu_item_option_id' => $dish->options->first()->id,
                    'quantity' => 4,
                    'unit_price' => 50,
                ]],
                'payment_method' => 'cash',
                'fulfillment_type' => 'takeaway',
                'contact_name' => 'Walk-in',
                'contact_phone' => '+233541234567',
            ])
            ->assertStatus(422);

        expect($response->json('error'))->toBe('insufficient_stock')
            ->and($response->json('message'))->toContain('Chicken')
            ->and($response->json('can_override'))->toBeFalse();

        expect(\App\Models\Order::count())->toBe(0);
    });

    it('takes the order when there is enough', function () {
        $dish = dishNeeding($this->branch, 'Jollof', $this->chicken, 1);
        stockOf($this->chicken, $this->location, 50);
        ['user' => $cashier] = gateStaff(RoleEnum::SalesStaff->value, $this->branch);

        $this->actingAs($cashier)
            ->postJson('/v1/pos/orders', [
                'branch_id' => $this->branch->id,
                'items' => [[
                    'menu_item_id' => $dish->id,
                    'menu_item_option_id' => $dish->options->first()->id,
                    'quantity' => 2,
                    'unit_price' => 50,
                ]],
                'payment_method' => 'cash',
                'fulfillment_type' => 'takeaway',
                'contact_name' => 'Walk-in',
                'contact_phone' => '+233541234567',
            ])
            ->assertCreated();
    });

    it('will not let a cashier override', function () {
        $dish = dishNeeding($this->branch, 'Jollof', $this->chicken, 1);
        stockOf($this->chicken, $this->location, 0);
        ['user' => $cashier] = gateStaff(RoleEnum::SalesStaff->value, $this->branch);

        $this->actingAs($cashier)
            ->postJson('/v1/pos/orders', [
                'branch_id' => $this->branch->id,
                'items' => [[
                    'menu_item_id' => $dish->id,
                    'menu_item_option_id' => $dish->options->first()->id,
                    'quantity' => 1,
                    'unit_price' => 50,
                ]],
                'payment_method' => 'cash',
                'fulfillment_type' => 'takeaway',
                'contact_name' => 'Walk-in',
                'contact_phone' => '+233541234567',
                'override_stock_gate' => true,
                'override_reason' => 'trust me',
            ])
            ->assertStatus(422);

        expect(\App\Models\Order::count())->toBe(0);
    });

    it('lets a manager override, and records it', function () {
        $dish = dishNeeding($this->branch, 'Jollof', $this->chicken, 1);
        stockOf($this->chicken, $this->location, 0);
        ['user' => $manager] = gateStaff(RoleEnum::Manager->value, $this->branch);

        $this->actingAs($manager)
            ->postJson('/v1/pos/orders', [
                'branch_id' => $this->branch->id,
                'items' => [[
                    'menu_item_id' => $dish->id,
                    'menu_item_option_id' => $dish->options->first()->id,
                    'quantity' => 1,
                    'unit_price' => 50,
                ]],
                'payment_method' => 'cash',
                'fulfillment_type' => 'takeaway',
                'contact_name' => 'Walk-in',
                'contact_phone' => '+233541234567',
                'override_stock_gate' => true,
                'override_reason' => 'Delivery arrived, not yet recorded',
            ])
            ->assertCreated();

        $logged = DB::table('activity_log')->where('event', 'stock_gate_overridden')->first();

        expect($logged)->not->toBeNull()
            ->and($logged->properties)->toContain('Delivery arrived, not yet recorded');
    });
});

describe('the checkout-session path — the one the till actually takes', function () {
    /*
     * PosOrderController::store was gated first and the terminal does not use
     * it. The POS creates a checkout session and confirms it, so the gate was
     * live and inert at the same time: a sale of 23 portions went through
     * against a balance of 6. Both paths are covered now, and this describe
     * block exists so neither can be left behind again.
     */
    it('refuses a session it cannot make', function () {
        $dish = dishNeeding($this->branch, 'Jollof', $this->chicken, 2);
        stockOf($this->chicken, $this->location, 6);
        ['user' => $cashier] = gateStaff(RoleEnum::SalesStaff->value, $this->branch);

        $response = $this->actingAs($cashier)
            ->postJson('/v1/pos/checkout-sessions', [
                'branch_id' => $this->branch->id,
                'items' => [[
                    'menu_item_id' => $dish->id,
                    'menu_item_option_id' => $dish->options->first()->id,
                    'quantity' => 23,
                    'unit_price' => 50,
                ]],
                'payment_method' => 'cash',
                'fulfillment_type' => 'takeaway',
                'contact_name' => 'Walk-in',
                'contact_phone' => '+233541234567',
            ])
            ->assertStatus(422);

        expect($response->json('error'))->toBe('insufficient_stock')
            ->and($response->json('message'))->toContain('Chicken');
    });

    it('opens a session when there is enough', function () {
        $dish = dishNeeding($this->branch, 'Jollof', $this->chicken, 1);
        stockOf($this->chicken, $this->location, 100);
        ['user' => $cashier] = gateStaff(RoleEnum::SalesStaff->value, $this->branch);

        $this->actingAs($cashier)
            ->postJson('/v1/pos/checkout-sessions', [
                'branch_id' => $this->branch->id,
                'items' => [[
                    'menu_item_id' => $dish->id,
                    'menu_item_option_id' => $dish->options->first()->id,
                    'quantity' => 2,
                    'unit_price' => 50,
                ]],
                'payment_method' => 'cash',
                'fulfillment_type' => 'takeaway',
                'contact_name' => 'Walk-in',
                'contact_phone' => '+233541234567',
            ])
            ->assertSuccessful();
    });

    it('will not let a cashier override on this path either', function () {
        $dish = dishNeeding($this->branch, 'Jollof', $this->chicken, 1);
        stockOf($this->chicken, $this->location, 0);
        ['user' => $cashier] = gateStaff(RoleEnum::SalesStaff->value, $this->branch);

        $this->actingAs($cashier)
            ->postJson('/v1/pos/checkout-sessions', [
                'branch_id' => $this->branch->id,
                'items' => [[
                    'menu_item_id' => $dish->id,
                    'menu_item_option_id' => $dish->options->first()->id,
                    'quantity' => 1,
                    'unit_price' => 50,
                ]],
                'payment_method' => 'cash',
                'fulfillment_type' => 'takeaway',
                'contact_name' => 'Walk-in',
                'contact_phone' => '+233541234567',
                'override_stock_gate' => true,
                'override_reason' => 'trust me',
            ])
            ->assertStatus(422);
    });
});

describe('the advisory endpoint', function () {
    it('tells the till which options it can still make', function () {
        $plenty = dishNeeding($this->branch, 'Jollof', $this->chicken, 1);
        $none = dishNeeding($this->branch, 'Chicken Special', $this->chicken, 100);
        stockOf($this->chicken, $this->location, 10);

        ['user' => $cashier] = gateStaff(RoleEnum::SalesStaff->value, $this->branch);

        $map = $this->actingAs($cashier)
            ->getJson("/v1/pos/stock-gate?branch_id={$this->branch->id}")
            ->assertSuccessful()
            ->json('data.sellable');

        expect($map[$plenty->options->first()->id])->toBeTrue()
            ->and($map[$none->options->first()->id])->toBeFalse();
    });

    it('judges a basket before the customer commits', function () {
        $dish = dishNeeding($this->branch, 'Jollof', $this->chicken, 1);
        stockOf($this->chicken, $this->location, 2);
        ['user' => $cashier] = gateStaff(RoleEnum::SalesStaff->value, $this->branch);

        $result = $this->actingAs($cashier)
            ->postJson('/v1/pos/stock-gate/check', [
                'branch_id' => $this->branch->id,
                'items' => [['menu_item_option_id' => $dish->options->first()->id, 'quantity' => 5]],
            ])
            ->assertSuccessful()
            ->json('data');

        expect($result['can_sell'])->toBeFalse()
            ->and($result['shortfalls'][0]['item_name'])->toBe('Chicken');
    });

    it('will not report on another branch', function () {
        $other = Branch::factory()->create();
        ['user' => $cashier] = gateStaff(RoleEnum::SalesStaff->value, $this->branch);

        $this->actingAs($cashier)
            ->getJson("/v1/pos/stock-gate?branch_id={$other->id}")
            ->assertNotFound();
    });
});
