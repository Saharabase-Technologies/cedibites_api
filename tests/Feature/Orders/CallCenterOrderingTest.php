<?php

use App\Enums\EmployeeStatus;
use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\CheckoutSession;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\CallCenterScopeCleanupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
|--------------------------------------------------------------------------
| The call centre's job, and its edges
|--------------------------------------------------------------------------
|
| They pick up the phone, take the order, and place it against the branch that
| will cook it. That is the whole job. From that moment the order belongs to
| the branch — the branch accepts it, prepares it, completes it. If the
| customer rings back to cancel, the agent asks and an admin decides.
|
| Two things were wrong. `update_orders` gave the agent the run of every
| branch's order queue, because asking for a cancellation rode on the same
| permission and they needed that. And the channel the order came in on was
| collected by the UI, validated, and then silently dropped — so every order
| the call centre has ever taken is recorded as a walk-in at a till.
|
*/

/**
 * @return array{user: User, employee: Employee}
 */
function ccStaff(string $role, ?Branch $branch = null): array
{
    $user = User::factory()->create();
    $employee = Employee::factory()->create([
        'user_id' => $user->id,
        'status' => EmployeeStatus::Active,
    ]);

    if ($branch) {
        $employee->branches()->attach($branch);
    }

    $user->syncRoles([$role]);

    return ['user' => $user->fresh(), 'employee' => $employee];
}

/** A dish available at the given branch, with its default option. */
function ccDish(Branch $branch, string $name = 'Jollof'): MenuItem
{
    $dish = MenuItem::factory()->create(['branch_id' => $branch->id, 'name' => $name]);
    $dish->branches()->attach($branch->id, ['is_available' => true]);

    return $dish->fresh(['options']);
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->create();
    $this->otherBranch = Branch::factory()->create();
});

/*
|--------------------------------------------------------------------------
| The three things the call centre must be able to do
|--------------------------------------------------------------------------
|
| Stated plainly so nothing quietly takes one of them away: place an order to
| any branch, read orders from any branch, and see the status of any order at
| any branch. They hold no branch assignment, which is what makes all three
| true — and also what breaks them one call site at a time, because
| `employee_branch` cannot tell "not confined to a branch" apart from "assigned
| no branches". Every reader has to be told which one it means.
|
*/

describe('the call centre reaches every branch', function () {
    it('1. places an order to any branch', function (string $branchKey) {
        ['user' => $agent] = ccStaff(RoleEnum::CallCenter->value);
        $branch = $this->{$branchKey};
        $dish = ccDish($branch);

        $this->actingAs($agent)
            ->postJson('/v1/pos/checkout-sessions', [
                'branch_id' => $branch->id,
                'items' => [[
                    'menu_item_id' => $dish->id,
                    'menu_item_option_id' => $dish->options->first()->id,
                    'quantity' => 1,
                    'unit_price' => 20,
                ]],
                'payment_method' => 'cash',
                'fulfillment_type' => 'delivery',
                'contact_name' => 'Ama',
                'contact_phone' => '+233541234567',
                'order_source' => 'phone',
            ])
            ->assertSuccessful();
    })->with(['branch', 'otherBranch']);

    it('2. reads orders from every branch in one list', function () {
        ['user' => $agent] = ccStaff(RoleEnum::CallCenter->value);
        Order::factory()->create(['branch_id' => $this->branch->id]);
        Order::factory()->create(['branch_id' => $this->otherBranch->id]);

        $response = $this->actingAs($agent)
            ->getJson('/v1/employee/orders')
            ->assertSuccessful();

        expect($response->json('data.data'))->toHaveCount(2);
    });

    /**
     * This one was returning 404 for every order in the business.
     * OrderController::canAccessOrder asked the branch pivot and read the empty
     * set as "no branches" — so an agent taking a call about an order could not
     * open it to say what was happening to it.
     */
    it('3. sees the status of an order at any branch', function (string $branchKey) {
        ['user' => $agent] = ccStaff(RoleEnum::CallCenter->value);
        $order = Order::factory()->create([
            'branch_id' => $this->{$branchKey}->id,
            'status' => 'preparing',
        ]);

        $this->actingAs($agent)
            ->getJson("/v1/orders/{$order->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'preparing');
    })->with(['branch', 'otherBranch']);

    it('still hides another branch\'s order from a cashier', function () {
        ['user' => $cashier] = ccStaff(RoleEnum::SalesStaff->value, $this->branch);
        $order = Order::factory()->create(['branch_id' => $this->otherBranch->id]);

        $this->actingAs($cashier)
            ->getJson("/v1/orders/{$order->id}")
            ->assertNotFound();
    });

    /**
     * Reading is not editing. Widening the order read to company-wide roles must
     * not hand the call centre the edit and delete paths that share the same
     * access check — they are gated on permissions the role does not hold.
     */
    it('cannot edit or delete the order it can read', function () {
        ['user' => $agent] = ccStaff(RoleEnum::CallCenter->value);
        $order = Order::factory()->create(['branch_id' => $this->otherBranch->id]);

        $this->actingAs($agent)
            ->patchJson("/v1/orders/{$order->id}", ['contact_name' => 'Changed'])
            ->assertForbidden();

        $this->actingAs($agent)
            ->deleteJson("/v1/orders/{$order->id}")
            ->assertForbidden();
    });
});

/*
|--------------------------------------------------------------------------
| The permission split
|--------------------------------------------------------------------------
*/

describe('placing an order is not running one', function () {
    it('does not let the call centre advance an order', function () {
        ['user' => $agent] = ccStaff(RoleEnum::CallCenter->value);
        $order = Order::factory()->create(['branch_id' => $this->branch->id, 'status' => 'received']);

        $this->actingAs($agent)
            ->patchJson("/v1/employee/orders/{$order->id}/status", ['status' => 'accepted'])
            ->assertForbidden();

        expect($order->fresh()->status)->toBe('received');
    });

    it('lets the call centre ask for a cancellation at any branch', function (string $branchKey) {
        ['user' => $agent] = ccStaff(RoleEnum::CallCenter->value);
        $order = Order::factory()->create(['branch_id' => $this->{$branchKey}->id, 'status' => 'received']);

        $this->actingAs($agent)
            ->postJson("/v1/employee/orders/{$order->id}/request-cancel", [
                'reason' => 'Customer rang back to cancel.',
            ])
            ->assertSuccessful();

        expect($order->fresh()->status)->toBe('cancel_requested');
    })->with(['branch', 'otherBranch']);

    it('keeps the cancel request separate from order handling on the role', function () {
        ['user' => $agent] = ccStaff(RoleEnum::CallCenter->value);

        expect($agent->can(Permission::OrderCancelRequest->value))->toBeTrue()
            ->and($agent->can(Permission::UpdateOrders->value))->toBeFalse()
            ->and($agent->can(Permission::CreateOrders->value))->toBeTrue();
    });

    it('still lets a cashier advance their own branch\'s orders', function () {
        ['user' => $cashier] = ccStaff(RoleEnum::SalesStaff->value, $this->branch);
        $order = Order::factory()->create(['branch_id' => $this->branch->id, 'status' => 'received']);

        $this->actingAs($cashier)
            ->patchJson("/v1/employee/orders/{$order->id}/status", ['status' => 'accepted'])
            ->assertSuccessful();
    });
});

/*
|--------------------------------------------------------------------------
| Cancellation requests were unscoped
|--------------------------------------------------------------------------
*/

describe('a cancel request is scoped to orders you have business with', function () {
    it('refuses a cashier the orders of a branch they do not work at', function () {
        ['user' => $cashier] = ccStaff(RoleEnum::SalesStaff->value, $this->branch);
        $order = Order::factory()->create(['branch_id' => $this->otherBranch->id, 'status' => 'received']);

        $this->actingAs($cashier)
            ->postJson("/v1/employee/orders/{$order->id}/request-cancel", ['reason' => 'Nosy.'])
            ->assertForbidden();

        expect($order->fresh()->status)->toBe('received');
    });

    it('allows a cashier their own branch\'s orders', function () {
        ['user' => $cashier] = ccStaff(RoleEnum::SalesStaff->value, $this->branch);
        $order = Order::factory()->create(['branch_id' => $this->branch->id, 'status' => 'received']);

        $this->actingAs($cashier)
            ->postJson("/v1/employee/orders/{$order->id}/request-cancel", ['reason' => 'Ran out of rice.'])
            ->assertSuccessful();
    });
});

/*
|--------------------------------------------------------------------------
| Which branch the order is for
|--------------------------------------------------------------------------
*/

describe('the branch belongs to the order, not the agent', function () {
    it('lets a branchless agent open a session at any branch', function () {
        ['user' => $agent] = ccStaff(RoleEnum::CallCenter->value);
        $dish = ccDish($this->otherBranch);

        $this->actingAs($agent)
            ->postJson('/v1/pos/checkout-sessions', [
                'branch_id' => $this->otherBranch->id,
                'items' => [[
                    'menu_item_id' => $dish->id,
                    'menu_item_option_id' => $dish->options->first()->id,
                    'quantity' => 1,
                    'unit_price' => 20,
                ]],
                'payment_method' => 'cash',
                'fulfillment_type' => 'delivery',
                'contact_name' => 'Ama',
                'contact_phone' => '+233541234567',
                'order_source' => 'phone',
            ])
            ->assertSuccessful();
    });

    it('still refuses a cashier a branch they do not work at', function () {
        ['user' => $cashier] = ccStaff(RoleEnum::SalesStaff->value, $this->branch);
        $dish = ccDish($this->otherBranch);

        $this->actingAs($cashier)
            ->postJson('/v1/pos/checkout-sessions', [
                'branch_id' => $this->otherBranch->id,
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
            ])
            ->assertForbidden();
    });
});

/*
|--------------------------------------------------------------------------
| The channel the order came in on
|--------------------------------------------------------------------------
*/

describe('order source survives the trip', function () {
    it('records the channel the agent took the call on', function (string $source) {
        ['user' => $agent] = ccStaff(RoleEnum::CallCenter->value);
        $dish = ccDish($this->branch);

        $this->actingAs($agent)
            ->postJson('/v1/pos/checkout-sessions', [
                'branch_id' => $this->branch->id,
                'items' => [[
                    'menu_item_id' => $dish->id,
                    'menu_item_option_id' => $dish->options->first()->id,
                    'quantity' => 1,
                    'unit_price' => 20,
                ]],
                'payment_method' => 'cash',
                'fulfillment_type' => 'delivery',
                'contact_name' => 'Ama',
                'contact_phone' => '+233541234567',
                'order_source' => $source,
            ])
            ->assertSuccessful();

        expect(CheckoutSession::latest('id')->first()->order_source)->toBe($source);

        // …and it survives the trip onto the order itself, which is the thing
        // analytics reads. Confirming the cash is what turns a session into one.
        $token = CheckoutSession::latest('id')->first()->session_token;
        $this->actingAs($agent)
            ->postJson("/v1/pos/checkout-sessions/{$token}/confirm-cash", ['amount_paid' => 20])
            ->assertSuccessful();

        expect(Order::latest('id')->first()->order_source)->toBe($source);
    })->with(['phone', 'whatsapp', 'social_media']);

    /**
     * A till that says nothing is a till. The field is optional so an existing
     * POS client keeps working unchanged.
     */
    it('falls back to the till when nothing is said', function () {
        ['user' => $cashier] = ccStaff(RoleEnum::SalesStaff->value, $this->branch);
        $dish = ccDish($this->branch);

        $this->actingAs($cashier)
            ->postJson('/v1/pos/checkout-sessions', [
                'branch_id' => $this->branch->id,
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
            ])
            ->assertSuccessful();

        expect(CheckoutSession::latest('id')->first()->order_source)->toBe('pos');
    });

    it('refuses a channel that is not one of ours', function () {
        ['user' => $agent] = ccStaff(RoleEnum::CallCenter->value);
        $dish = ccDish($this->branch);

        $this->actingAs($agent)
            ->postJson('/v1/pos/checkout-sessions', [
                'branch_id' => $this->branch->id,
                'items' => [[
                    'menu_item_id' => $dish->id,
                    'menu_item_option_id' => $dish->options->first()->id,
                    'quantity' => 1,
                    'unit_price' => 20,
                ]],
                'payment_method' => 'cash',
                'fulfillment_type' => 'delivery',
                'contact_name' => 'Ama',
                'contact_phone' => '+233541234567',
                'order_source' => 'carrier_pigeon',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order_source');
    });
});

/*
|--------------------------------------------------------------------------
| The cleanup seeder
|--------------------------------------------------------------------------
*/

describe('CallCenterScopeCleanupSeeder', function () {
    it('revokes the grant RoleSeeder cannot remove', function () {
        $role = \Spatie\Permission\Models\Role::where('name', RoleEnum::CallCenter->value)->firstOrFail();
        $role->givePermissionTo(Permission::UpdateOrders->value);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        ['user' => $agent] = ccStaff(RoleEnum::CallCenter->value);
        expect($agent->can(Permission::UpdateOrders->value))->toBeTrue();

        $this->seed(CallCenterScopeCleanupSeeder::class);

        expect($agent->fresh()->can(Permission::UpdateOrders->value))->toBeFalse()
            ->and($agent->fresh()->can(Permission::OrderCancelRequest->value))->toBeTrue();
    });

    it('strips a grant attached straight to the user', function () {
        ['user' => $agent] = ccStaff(RoleEnum::CallCenter->value);
        $agent->givePermissionTo(Permission::UpdateOrders->value);

        expect($agent->fresh()->can(Permission::UpdateOrders->value))->toBeTrue();

        $this->seed(CallCenterScopeCleanupSeeder::class);

        expect($agent->fresh()->can(Permission::UpdateOrders->value))->toBeFalse();
    });

    it('is safe to run twice', function () {
        $this->seed(CallCenterScopeCleanupSeeder::class);
        $this->seed(CallCenterScopeCleanupSeeder::class);

        ['user' => $agent] = ccStaff(RoleEnum::CallCenter->value);

        expect($agent->can(Permission::OrderCancelRequest->value))->toBeTrue()
            ->and($agent->can(Permission::UpdateOrders->value))->toBeFalse();
    });
});

/*
|--------------------------------------------------------------------------
| The call centre works a shift too
|--------------------------------------------------------------------------
|
| They sit down, take calls and go home like everyone else — they just do not
| sit in a branch. `shifts.branch_id` being NOT NULL meant they could not start
| one at all, so they had no shifts, and therefore no My Shifts and no My Sales.
|
*/

describe('shifts without a branch', function () {
    it('starts a shift for an agent who belongs to no branch', function () {
        ['user' => $agent] = ccStaff(RoleEnum::CallCenter->value);

        $this->actingAs($agent)
            ->postJson('/v1/shifts', [])
            ->assertSuccessful()
            ->assertJsonPath('data.branchId', null);
    });

    it('lets the agent read the shift back', function () {
        ['user' => $agent] = ccStaff(RoleEnum::CallCenter->value);

        $this->actingAs($agent)->postJson('/v1/shifts', [])->assertSuccessful();

        $response = $this->actingAs($agent)->getJson('/v1/shifts')->assertSuccessful();

        expect($response->json('data'))->toHaveCount(1);
    });

    it('still requires a branch of someone who works at one', function () {
        ['user' => $cashier] = ccStaff(RoleEnum::SalesStaff->value, $this->branch);

        $this->actingAs($cashier)
            ->postJson('/v1/shifts', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
    });

    it('accepts a branch from someone who works at one', function () {
        ['user' => $cashier] = ccStaff(RoleEnum::SalesStaff->value, $this->branch);

        $this->actingAs($cashier)
            ->postJson('/v1/shifts', ['branch_id' => $this->branch->id])
            ->assertSuccessful()
            ->assertJsonPath('data.branchId', (string) $this->branch->id);
    });
});
