<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\ShiftOrder;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
|--------------------------------------------------------------------------
| Whoever accepts an order owns it
|--------------------------------------------------------------------------
|
| An order placed on the website arrives with no employee against it. Until
| somebody accepts it, it belongs to nobody — and every figure that measures
| staff against sales reads `assigned_employee_id`, so it reports as revenue
| nobody is accountable for.
|
| Accepting is the moment of claim. It stamps the accepting employee on the
| order and puts the order on that employee's open shift, which is the figure
| they are counted against at the end of the day.
|
| The one thing it must never do is take an order away from the person who
| already owned it. A till sale and a call-centre order both name their owner
| from the moment they are created; accepting one later must leave that alone.
|
*/

/** @return array{user: User, employee: Employee} */
function attrStaff(Branch $branch, ?string $role = null): array
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

function attrOnlineOrder(Branch $branch): Order
{
    $order = Order::factory()->create([
        'branch_id' => $branch->id,
        'assigned_employee_id' => null,
        'order_source' => 'online',
        'status' => 'received',
        'total_amount' => 96.00,
    ]);

    Payment::factory()->create([
        'order_id' => $order->id,
        'payment_method' => 'mobile_money',
        'payment_status' => 'completed',
        'amount' => 96.00,
    ]);

    return $order->fresh();
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::factory()->create();
});

it('attributes an unclaimed online order to whoever accepts it', function () {
    ['user' => $user, 'employee' => $employee] = attrStaff($this->branch);
    $order = attrOnlineOrder($this->branch);

    $this->actingAs($user)
        ->patchJson("/v1/employee/orders/{$order->id}/status", ['status' => 'accepted'])
        ->assertOk();

    expect($order->fresh()->assigned_employee_id)->toBe($employee->id);
});

it('puts the accepted order on the accepting employee\'s open shift', function () {
    ['user' => $user, 'employee' => $employee] = attrStaff($this->branch);

    $shift = Shift::factory()->create([
        'employee_id' => $employee->id,
        'branch_id' => $this->branch->id,
        'login_at' => now()->subHour(),
        'logout_at' => null,
        'total_sales' => 0,
        'order_count' => 0,
    ]);

    $order = attrOnlineOrder($this->branch);

    $this->actingAs($user)
        ->patchJson("/v1/employee/orders/{$order->id}/status", ['status' => 'accepted'])
        ->assertOk();

    expect(ShiftOrder::where('shift_id', $shift->id)->where('order_id', $order->id)->exists())->toBeTrue();

    $shift->refresh();
    expect((float) $shift->total_sales)->toBe(96.00)
        ->and($shift->order_count)->toBe(1);
});

it('leaves an order that already names its owner alone', function () {
    ['user' => $accepter] = attrStaff($this->branch);
    ['employee' => $agent] = attrStaff($this->branch, RoleEnum::CallCenter->value);

    // A call-centre order: the agent who took the call is on it from creation.
    $order = Order::factory()->create([
        'branch_id' => $this->branch->id,
        'assigned_employee_id' => $agent->id,
        'order_source' => 'phone',
        'status' => 'received',
    ]);

    $this->actingAs($accepter)
        ->patchJson("/v1/employee/orders/{$order->id}/status", ['status' => 'accepted'])
        ->assertOk();

    expect($order->fresh()->assigned_employee_id)->toBe($agent->id);
});

it('accepts the order even when the accepting employee has not clocked in', function () {
    ['user' => $user, 'employee' => $employee] = attrStaff($this->branch);
    $order = attrOnlineOrder($this->branch);

    $this->actingAs($user)
        ->patchJson("/v1/employee/orders/{$order->id}/status", ['status' => 'accepted'])
        ->assertOk();

    expect($order->fresh())
        ->status->toBe('accepted')
        ->assigned_employee_id->toBe($employee->id);
    expect(ShiftOrder::count())->toBe(0);
});

it('does not double-count an order the till already put on the shift', function () {
    ['user' => $user, 'employee' => $employee] = attrStaff($this->branch);

    $shift = Shift::factory()->create([
        'employee_id' => $employee->id,
        'branch_id' => $this->branch->id,
        'login_at' => now()->subHour(),
        'logout_at' => null,
        'total_sales' => 96.00,
        'order_count' => 1,
    ]);

    $order = attrOnlineOrder($this->branch);

    // The till already recorded it against the shift.
    ShiftOrder::create([
        'shift_id' => $shift->id,
        'order_id' => $order->id,
        'order_total' => 96.00,
    ]);

    $this->actingAs($user)
        ->patchJson("/v1/employee/orders/{$order->id}/status", ['status' => 'accepted'])
        ->assertOk();

    $shift->refresh();
    expect((float) $shift->total_sales)->toBe(96.00)
        ->and($shift->order_count)->toBe(1)
        ->and(ShiftOrder::where('shift_id', $shift->id)->count())->toBe(1);
});
