<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| The staff sales report: sales for whoever rang them up, money for whoever
| took it (D44)
|--------------------------------------------------------------------------
*/

function ssStaff(Branch $branch, string $name, string $role = RoleEnum::SalesStaff->value): array
{
    $user = User::factory()->create(['name' => $name]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => EmployeeStatus::Active]);
    $employee->branches()->attach($branch);
    $user->syncRoles([$role]);

    return ['user' => $user->fresh(), 'employee' => $employee];
}

function ssDish(Branch $branch, string $name): MenuItem
{
    $dish = MenuItem::factory()->create(['branch_id' => $branch->id, 'name' => $name]);
    $dish->branches()->attach($branch->id, ['is_available' => true]);

    return $dish->fresh(['options']);
}

function ssRing(User $cashier, Branch $branch, MenuItem $dish, array $overrides = [])
{
    $body = array_merge([
        'branch_id' => $branch->id,
        'items' => [[
            'menu_item_id' => $dish->id,
            'menu_item_option_id' => $dish->options->first()->id,
            'quantity' => 1,
            'unit_price' => 5,
        ]],
        'payment_method' => 'cash',
        'fulfillment_type' => 'dine_in',
        'contact_name' => 'Ama',
        'contact_phone' => '+233247879103',
    ], $overrides);

    $session = test()->actingAs($cashier)->postJson('/v1/pos/checkout-sessions', $body);

    if ($body['payment_method'] !== 'cash') {
        return $session;
    }

    return test()->actingAs($cashier)->postJson(
        '/v1/pos/checkout-sessions/'.$session->json('session_token').'/confirm-cash',
        ['amount_paid' => 500],
    );
}

/** The report's row for one person. */
function ssRow(Branch $branch, Employee $employee): array
{
    $rows = collect(app(AnalyticsService::class)->getStaffSalesMetrics([
        'branch_id' => $branch->id,
        'date_from' => now()->toDateString(),
        'date_to' => now()->toDateString(),
    ]));

    return $rows->firstWhere('employee_id', $employee->id) ?? [];
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    Notification::fake();

    $this->branch = Branch::factory()->create([
        'name' => 'Lakeside',
        'is_active' => true,
        'extended_staff_access' => true,
        'extended_order_access' => true,
    ]);

    ['user' => $this->rosina, 'employee' => $this->rosinaEmployee] = ssStaff($this->branch, 'Rosina');
    ['user' => $this->kofi, 'employee' => $this->kofiEmployee] = ssStaff($this->branch, 'Kofi');
    ['user' => $this->admin] = ssStaff($this->branch, 'Head Office', RoleEnum::Admin->value);

    $this->jollof = ssDish($this->branch, 'Jollof');
    $this->water = ssDish($this->branch, 'Water');
});

it('credits the order to whoever placed it and the round to whoever added it, money to whoever took it', function () {
    $order = Order::findOrFail(ssRing($this->rosina, $this->branch, $this->jollof)->assertCreated()->json('order.id'));
    $first = $order->goods_amount;
    ssRing($this->kofi, $this->branch, $this->water, ['add_to_order_id' => $order->id])->assertCreated();
    $water = (float) $this->water->options->first()->price;

    $rosina = ssRow($this->branch, $this->rosinaEmployee);
    $kofi = ssRow($this->branch, $this->kofiEmployee);

    expect($rosina['sales_total'])->toBe($first)
        ->and($rosina['total_orders'])->toBe(1)
        ->and($rosina['cash_total'])->toBe($first)
        ->and($kofi['sales_total'])->toBe($water)
        ->and($kofi['rounds_added'])->toBe(1)
        ->and($kofi['total_orders'])->toBe(0)
        ->and($kofi['cash_total'])->toBe($water);
});

it('puts money taken on someone else\'s order with whoever took it, and leaves the sale with whoever placed it', function () {
    $order = Order::findOrFail(ssRing($this->rosina, $this->branch, $this->jollof, ['payment_method' => 'pay_later'])->assertCreated()->json('order.id'));

    $this->actingAs($this->kofi)
        ->postJson("/v1/pos/orders/{$order->id}/payments", ['method' => 'cash', 'handed_over' => $order->goods_amount])
        ->assertCreated();

    $rosina = ssRow($this->branch, $this->rosinaEmployee);
    $kofi = ssRow($this->branch, $this->kofiEmployee);

    expect($rosina['sales_total'])->toBe($order->goods_amount)
        ->and($rosina['total_revenue'])->toBe(0.0)
        ->and($kofi['sales_total'])->toBe(0.0)
        ->and($kofi['cash_total'])->toBe($order->goods_amount)
        ->and($kofi['total_revenue'])->toBe($order->goods_amount);
});

it('leaves a cancelled round out of the sales', function () {
    $order = Order::findOrFail(ssRing($this->rosina, $this->branch, $this->jollof)->assertCreated()->json('order.id'));
    ssRing($this->kofi, $this->branch, $this->water, ['add_to_order_id' => $order->id])->assertCreated();
    $round = $order->rounds()->sole();

    $this->actingAs($this->admin)
        ->postJson("/v1/admin/orders/{$order->id}/rounds/{$round->id}/cancel", ['reason' => 'Wrong table'])
        ->assertOk();

    $kofi = ssRow($this->branch, $this->kofiEmployee);
    expect($kofi['sales_total'] ?? 0.0)->toBe(0.0)
        ->and($kofi['rounds_added'] ?? 0)->toBe(0)
        ->and(ssRow($this->branch, $this->rosinaEmployee)['sales_total'])->toBe($order->fresh()->goods_amount);
});
