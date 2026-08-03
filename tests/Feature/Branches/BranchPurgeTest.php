<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Retiring a branch without taking anyone's account with it
|--------------------------------------------------------------------------
|
| Written against a real situation: Test Branch on production held 51 orders,
| 8 staff assignments and a stock ledger, and had to go. The obvious way to do
| that job is wrong in ways nothing on screen would show you, so each of those
| ways is pinned here.
|
| The one that matters most: a member of staff attached to the branch being
| purged had served 486 orders at OTHER branches. `assigned_employee_id` is
| ON DELETE SET NULL, so deleting that employee would have silently erased who
| served all 486 real sales. Staff are unlinked, never deleted.
*/

it('deletes the branch orders and everything hanging off them', function () {
    $branch = Branch::factory()->create();
    $order = Order::factory()->create(['branch_id' => $branch->id]);

    DB::table('order_status_history')->insert([
        'order_id' => $order->id, 'status' => 'preparing', 'changed_at' => now(),
    ]);

    $this->artisan('branch:purge', ['--branch' => $branch->id, '--apply' => true, '--force' => true])
        ->assertSuccessful();

    expect(DB::table('orders')->where('branch_id', $branch->id)->count())->toBe(0)
        ->and(DB::table('order_status_history')->where('order_id', $order->id)->count())->toBe(0);
});

it('unlinks staff without deleting a single account', function () {
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create();
    $employee->branches()->attach($branch->id);

    $this->artisan('branch:purge', ['--branch' => $branch->id, '--apply' => true, '--force' => true])
        ->assertSuccessful();

    expect(DB::table('employee_branch')->where('branch_id', $branch->id)->count())->toBe(0)
        ->and(Employee::find($employee->id))->not->toBeNull()
        ->and(User::find($employee->user_id))->not->toBeNull();
});

it('leaves the staff member\'s orders at other branches alone', function () {
    // The 486-order case. This is the assertion the whole command exists for.
    $doomed = Branch::factory()->create();
    $keeper = Branch::factory()->create();

    $employee = Employee::factory()->create();
    $employee->branches()->attach($doomed->id);

    $elsewhere = Order::factory()->create([
        'branch_id' => $keeper->id,
        'assigned_employee_id' => $employee->id,
    ]);

    $this->artisan('branch:purge', ['--branch' => $doomed->id, '--apply' => true, '--force' => true])
        ->assertSuccessful();

    $elsewhere->refresh();

    expect($elsewhere->exists)->toBeTrue()
        ->and($elsewhere->assigned_employee_id)->toBe($employee->id);
});

it('refuses outright when the branch owns menu rows the whole business shares', function () {
    // menu_items.branch_id is ON DELETE CASCADE and one dish row now serves
    // every branch, so this would delete the dish everywhere.
    $branch = Branch::factory()->create();
    MenuItem::factory()->create(['branch_id' => $branch->id]);

    $this->artisan('branch:purge', ['--branch' => $branch->id, '--apply' => true, '--force' => true])
        ->expectsOutputToContain('records that other branches share')
        ->assertFailed();

    expect(MenuItem::where('branch_id', $branch->id)->count())->toBe(1);
});

it('never deletes the branch row itself, only deactivates it', function () {
    $branch = Branch::factory()->create(['is_active' => true]);
    Order::factory()->create(['branch_id' => $branch->id]);

    $this->artisan('branch:purge', ['--branch' => $branch->id, '--apply' => true, '--force' => true])
        ->assertSuccessful();

    expect(Branch::find($branch->id))->not->toBeNull()
        ->and(Branch::find($branch->id)->is_active)->toBeFalse();
});

it('changes nothing without --apply', function () {
    $branch = Branch::factory()->create(['is_active' => true]);
    Order::factory()->create(['branch_id' => $branch->id]);

    $this->artisan('branch:purge', ['--branch' => $branch->id])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(DB::table('orders')->where('branch_id', $branch->id)->count())->toBe(1)
        ->and(Branch::find($branch->id)->is_active)->toBeTrue();
});

it('touches no other branch', function () {
    $doomed = Branch::factory()->create();
    $keeper = Branch::factory()->create();

    Order::factory()->count(2)->create(['branch_id' => $doomed->id]);
    Order::factory()->count(3)->create(['branch_id' => $keeper->id]);

    $this->artisan('branch:purge', ['--branch' => $doomed->id, '--apply' => true, '--force' => true])
        ->assertSuccessful();

    expect(DB::table('orders')->where('branch_id', $doomed->id)->count())->toBe(0)
        ->and(DB::table('orders')->where('branch_id', $keeper->id)->count())->toBe(3)
        ->and(Branch::find($keeper->id)->is_active)->toBeTrue();
});

it('reports the revenue that leaves the books', function () {
    $branch = Branch::factory()->create();
    Order::factory()->create([
        'branch_id' => $branch->id,
        'total_amount' => 1500.00,
        'status' => 'completed',
    ]);

    $this->artisan('branch:purge', ['--branch' => $branch->id])
        ->expectsOutputToContain('1,500.00')
        ->assertSuccessful();
});

it('fails cleanly on a branch that does not exist', function () {
    $this->artisan('branch:purge', ['--branch' => 999999])->assertFailed();
});
