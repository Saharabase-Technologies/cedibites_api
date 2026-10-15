<?php

use App\Domain\Inventory\Recipes\RecipeDeductionService;
use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
|--------------------------------------------------------------------------
| Pay later: placed now, to the kitchen now, paid afterwards (D1 to D7)
|--------------------------------------------------------------------------
|
| An order has a total and a list of payments. It is paid when the payments
| cover the total. A Pay later order starts with none.
|
*/

function oweStaff(Branch $branch, string $name, string $role = RoleEnum::SalesStaff->value): array
{
    $user = User::factory()->create(['name' => $name]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => EmployeeStatus::Active]);
    $employee->branches()->attach($branch);
    $user->syncRoles([$role]);

    return ['user' => $user->fresh(), 'employee' => $employee];
}

function oweDish(Branch $branch): MenuItem
{
    $dish = MenuItem::factory()->create(['branch_id' => $branch->id, 'name' => 'Jollof']);
    $dish->branches()->attach($branch->id, ['is_available' => true]);

    return $dish->fresh(['options']);
}

/** Rosina taps Pay later for Ama's order. */
function owePlace(User $cashier, Branch $branch, array $overrides = [])
{
    $dish = oweDish($branch);

    return test()->actingAs($cashier)->postJson('/v1/pos/checkout-sessions', array_merge([
        'branch_id' => $branch->id,
        'items' => [[
            'menu_item_id' => $dish->id,
            'menu_item_option_id' => $dish->options->first()->id,
            'quantity' => 1,
            'unit_price' => 90,
        ]],
        'payment_method' => 'pay_later',
        'fulfillment_type' => 'dine_in',
        'contact_name' => 'Ama',
        'contact_phone' => '+233247879103',
    ], $overrides));
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    // Kept open, so the sale is not refused by the time of day.
    $this->branch = Branch::factory()->create([
        'name' => 'Lakeside',
        'is_active' => true,
        'extended_staff_access' => true,
        'extended_order_access' => true,
    ]);

    ['user' => $this->rosina, 'employee' => $this->rosinaEmployee] = oweStaff($this->branch, 'Rosina');
});

describe('placing a Pay later order', function () {
    it('makes the order at once, with nothing paid', function () {
        $response = owePlace($this->rosina, $this->branch)
            ->assertCreated()
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('order.paid_later', true)
            ->assertJsonPath('order.payment_state', 'not_paid')
            ->assertJsonPath('order.amount_paid', 0);

        $order = Order::findOrFail($response->json('order.id'));

        // The whole of the goods is owed. The till charges the menu's price.
        expect($response->json('order.balance_due'))->toBe($order->goods_amount)
            ->and($order->goods_amount)->toBeGreaterThan(0.0)
            ->and($order->paid_later)->toBeTrue()
            ->and($order->placed_unpaid_at)->not->toBeNull()
            ->and($order->fully_paid_at)->toBeNull()
            ->and($order->payments()->count())->toBe(0)
            ->and($order->assigned_employee_id)->toBe($this->rosinaEmployee->id);
    });

    it('runs the same checks as any sale before it makes anything', function () {
        owePlace($this->rosina, $this->branch, ['promo_code' => 'NOSUCHCODE'])->assertStatus(422);

        $elsewhere = Branch::factory()->create(['is_active' => true, 'extended_staff_access' => true, 'extended_order_access' => true]);
        owePlace($this->rosina, $elsewhere)->assertForbidden();

        expect(Order::count())->toBe(0);
    });

    it('is not offered for a recorded past order, which was paid at the time', function () {
        owePlace($this->rosina, $this->branch, [
            'is_manual_entry' => true,
            'recorded_at' => now()->subHour()->toIso8601String(),
            'manual_entry_reason' => 'Power cut',
        ])->assertStatus(422);

        expect(Order::count())->toBe(0);
    });

    it('takes the stock when the order is placed, since the kitchen cooks it now', function () {
        $stock = $this->mock(RecipeDeductionService::class);
        $stock->shouldReceive('deductForOrder')->once();

        owePlace($this->rosina, $this->branch)->assertCreated();
    });

    it('gives the stock back when an unpaid Pay later order is cancelled', function () {
        $order = Order::findOrFail(owePlace($this->rosina, $this->branch)->json('order.id'));

        $stock = $this->mock(RecipeDeductionService::class);
        $stock->shouldReceive('reverseForOrder')->once();

        $order->update(['status' => 'cancelled', 'cancelled_at' => now()]);
    });
});

describe('the kitchen and the Order Manager', function () {
    beforeEach(function () {
        $this->payLater = Order::findOrFail(owePlace($this->rosina, $this->branch)->json('order.id'));

        // An online order whose MoMo prompt is still waiting. Not paid, not vouched for.
        $this->waiting = Order::factory()->create(['branch_id' => $this->branch->id, 'status' => 'received', 'order_source' => 'online']);
        Payment::create([
            'order_id' => $this->waiting->id,
            'payment_method' => 'mobile_money',
            'payment_status' => 'pending',
            'amount' => $this->waiting->goods_amount,
        ]);
    });

    it('shows a Pay later order on the Order Manager, and not an unpaid online one', function () {
        $ids = collect($this->actingAs($this->rosina)
            ->getJson('/v1/order-manager/orders?branch_id='.$this->branch->id)
            ->assertOk()
            ->json('data'))->pluck('id');

        expect($ids)->toContain($this->payLater->id)
            ->and($ids)->not->toContain($this->waiting->id);
    });

    it('shows a Pay later order on the kitchen display, and not an unpaid online one', function () {
        ['user' => $cook] = oweStaff($this->branch, 'Kojo', RoleEnum::Kitchen->value);

        $ids = collect($this->actingAs($cook)
            ->getJson('/v1/kitchen/orders?branch_id='.$this->branch->id)
            ->assertOk()
            ->json('data'))->pluck('id');

        expect($ids)->toContain($this->payLater->id)
            ->and($ids)->not->toContain($this->waiting->id);
    });
});
