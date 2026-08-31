<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
|--------------------------------------------------------------------------
| Has this order's receipt ever been printed?
|--------------------------------------------------------------------------
|
| The till knew it had printed one, but only for the sale it had just rung up
| and only on that device. So a screen opened anywhere else could not tell a
| receipt already handed to a customer from one that had never been printed —
| which is the whole difference between offering "Print receipt" and offering
| "Reprint receipt".
|
| The first print is the one that matters and its time must not move. The count
| keeps climbing, so a run of reprints on one order stays visible.
|
*/

/** @return array{user: User, employee: Employee} */
function printStaff(Branch $branch, ?string $role = null): array
{
    $user = User::factory()->create();
    $employee = Employee::factory()->create([
        'user_id' => $user->id,
        'status' => EmployeeStatus::Active,
    ]);
    $employee->branches()->attach($branch);
    $user->syncRoles([$role ?? RoleEnum::SalesStaff->value]);

    return ['user' => $user->fresh(), 'employee' => $employee];
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::factory()->create();
});

it('starts every order as never printed', function () {
    // Read back from the database: the count's zero is a column default, so it
    // exists on the row rather than on the instance that was just inserted.
    $order = Order::factory()->create(['branch_id' => $this->branch->id])->fresh();

    expect($order->receipt_printed_at)->toBeNull()
        ->and($order->receipt_print_count)->toBe(0);
});

it('records the first print', function () {
    ['user' => $user] = printStaff($this->branch);
    $order = Order::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($user)
        ->postJson("/v1/employee/orders/{$order->id}/receipt-printed")
        ->assertOk();

    $order->refresh();
    expect($order->receipt_printed_at)->not->toBeNull()
        ->and($order->receipt_print_count)->toBe(1);
});

it('keeps the first print time while counting the reprints', function () {
    ['user' => $user] = printStaff($this->branch);
    $firstPrint = now()->subHours(2);

    $order = Order::factory()->create([
        'branch_id' => $this->branch->id,
        'receipt_printed_at' => $firstPrint,
        'receipt_print_count' => 1,
    ]);

    $this->actingAs($user)
        ->postJson("/v1/employee/orders/{$order->id}/receipt-printed")
        ->assertOk();

    $order->refresh();
    expect($order->receipt_printed_at->timestamp)->toBe($firstPrint->timestamp)
        ->and($order->receipt_print_count)->toBe(2);
});

it('refuses an order from another branch', function () {
    ['user' => $user] = printStaff($this->branch);
    $elsewhere = Branch::factory()->create();
    $order = Order::factory()->create(['branch_id' => $elsewhere->id]);

    $this->actingAs($user)
        ->postJson("/v1/employee/orders/{$order->id}/receipt-printed")
        ->assertForbidden();

    expect($order->fresh()->receipt_print_count)->toBe(0);
});

it('sends the print state back on the order', function () {
    ['user' => $user] = printStaff($this->branch);
    $order = Order::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($user)
        ->postJson("/v1/employee/orders/{$order->id}/receipt-printed")
        ->assertOk()
        ->assertJsonPath('data.receipt_print_count', 1)
        ->assertJsonPath('data.id', $order->id);

    $this->actingAs($user)
        ->getJson("/v1/employee/orders?branch_id={$this->branch->id}")
        ->assertOk()
        ->assertJsonPath('data.data.0.receipt_print_count', 1);
});
