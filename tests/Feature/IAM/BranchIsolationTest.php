<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
|--------------------------------------------------------------------------
| Branch Isolation, Phase 1 — one branch cannot see or touch another's
|--------------------------------------------------------------------------
|
| Two things were wrong and this file pins both down.
|
| 1. `branch_id` was a filter the CLIENT chose. The manager portal filled it
|    from staffUser.branches[0], the admin portal from a ?branch= query param,
|    and dropping it altogether returned company-wide figures. Scope is now
|    decided on the server and intersected with the caller's assignment.
|
| 2. Analytics, the dashboard and the payment ledger were gated on
|    `view_orders`, which every staff role holds — so a till login could pull
|    the company's revenue. They are gated on `view_analytics` now.
|
*/

/**
 * A user + employee with a role, optionally attached to branches.
 *
 * @param  list<Branch>  $branches
 * @return array{user: User, employee: Employee}
 */
function isolatedStaff(string $role, array $branches = []): array
{
    $user = User::factory()->create();
    $employee = Employee::factory()->create([
        'user_id' => $user->id,
        'status' => EmployeeStatus::Active,
    ]);

    foreach ($branches as $branch) {
        $employee->branches()->attach($branch);
    }

    $user->assignRole($role);

    return ['user' => $user->fresh(), 'employee' => $employee];
}

/**
 * An order at a branch with a completed payment, so it counts as revenue.
 */
function paidOrderAt(Branch $branch, ?Customer $customer = null): Order
{
    $order = Order::factory()->create([
        'branch_id' => $branch->id,
        'status' => 'completed',
        'total_amount' => 100,
        'delivery_fee' => 0,
        ...($customer ? ['customer_id' => $customer->id] : []),
    ]);

    Payment::factory()->create([
        'order_id' => $order->id,
        'payment_status' => 'completed',
        'amount' => 100,
    ]);

    return $order->fresh();
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branchA = Branch::factory()->create(['name' => 'Ashaiman']);
    $this->branchB = Branch::factory()->create(['name' => 'Test Branch']);
});

/*
|--------------------------------------------------------------------------
| Reporting is not an order-handling permission
|--------------------------------------------------------------------------
*/

describe('a till login cannot read the books', function () {
    it('refuses the cashier every analytics endpoint', function (string $path) {
        ['user' => $cashier] = isolatedStaff(RoleEnum::SalesStaff->value, [$this->branchA]);

        $this->actingAs($cashier)->getJson($path)->assertForbidden();
    })->with([
        'sales' => '/v1/admin/analytics/sales',
        'staff sales' => '/v1/admin/analytics/staff-sales',
        'branch league table' => '/v1/admin/analytics/branch-performance',
        'revenue targets' => '/v1/admin/analytics/revenue-targets?year=2026&month=7',
        'daily report' => '/v1/admin/reports/daily',
        'dashboard' => '/v1/admin/dashboard',
        'payments' => '/v1/admin/payments',
    ]);

    it('refuses the kitchen and riders too', function (string $role) {
        ['user' => $user] = isolatedStaff($role, [$this->branchA]);

        $this->actingAs($user)->getJson('/v1/admin/analytics/sales')->assertForbidden();
    })->with([
        'kitchen' => RoleEnum::Kitchen->value,
        'rider' => RoleEnum::Rider->value,
        'call centre' => RoleEnum::CallCenter->value,
    ]);

    it('still lets the manager in', function () {
        ['user' => $manager] = isolatedStaff(RoleEnum::Manager->value, [$this->branchA]);

        $this->actingAs($manager)->getJson('/v1/admin/analytics/sales')->assertSuccessful();
    });

    it('refuses the cashier the customer contact export', function () {
        ['user' => $cashier] = isolatedStaff(RoleEnum::SalesStaff->value, [$this->branchA]);

        $this->actingAs($cashier)
            ->getJson('/v1/admin/customers/export-contacts')
            ->assertForbidden();
    });

    it('refuses the manager the customer contact export', function () {
        ['user' => $manager] = isolatedStaff(RoleEnum::Manager->value, [$this->branchA]);

        $this->actingAs($manager)
            ->getJson('/v1/admin/customers/export-contacts')
            ->assertForbidden();
    });
});

/*
|--------------------------------------------------------------------------
| Analytics scope is decided by the server, not the query string
|--------------------------------------------------------------------------
*/

describe('analytics branch scope', function () {
    beforeEach(function () {
        paidOrderAt($this->branchA);
        paidOrderAt($this->branchB);
    });

    it('gives a manager only their own branch when they ask for nothing', function () {
        ['user' => $manager] = isolatedStaff(RoleEnum::Manager->value, [$this->branchA]);

        $rows = $this->actingAs($manager)
            ->getJson('/v1/admin/analytics/branch-performance')
            ->assertSuccessful()
            ->json('data');

        expect(collect($rows)->pluck('id')->all())->toBe([$this->branchA->id]);
    });

    it('gives a manager nothing when they ask for someone else\'s branch', function () {
        ['user' => $manager] = isolatedStaff(RoleEnum::Manager->value, [$this->branchA]);

        $rows = $this->actingAs($manager)
            ->getJson("/v1/admin/analytics/branch-performance?branch_id={$this->branchB->id}")
            ->assertSuccessful()
            ->json('data');

        expect($rows)->toBe([]);
    });

    it('cannot be widened with a branch_ids array', function () {
        ['user' => $manager] = isolatedStaff(RoleEnum::Manager->value, [$this->branchA]);

        $rows = $this->actingAs($manager)
            ->getJson('/v1/admin/analytics/branch-performance?'.http_build_query([
                'branch_ids' => [$this->branchA->id, $this->branchB->id],
            ]))
            ->assertSuccessful()
            ->json('data');

        expect(collect($rows)->pluck('id')->all())->toBe([$this->branchA->id]);
    });

    it('gives a manager with no branch assignment nothing at all', function () {
        ['user' => $manager] = isolatedStaff(RoleEnum::Manager->value);

        $rows = $this->actingAs($manager)
            ->getJson('/v1/admin/analytics/branch-performance')
            ->assertSuccessful()
            ->json('data');

        expect($rows)->toBe([]);
    });

    it('leaves the admin unconfined', function () {
        ['user' => $admin] = isolatedStaff(RoleEnum::Admin->value, [$this->branchA]);

        $rows = $this->actingAs($admin)
            ->getJson('/v1/admin/analytics/branch-performance')
            ->assertSuccessful()
            ->json('data');

        expect(collect($rows)->pluck('id')->sort()->values()->all())
            ->toBe(collect([$this->branchA->id, $this->branchB->id])->sort()->values()->all());
    });

    it('scopes revenue targets to the caller\'s branches', function () {
        \App\Models\BranchRevenueTarget::create([
            'branch_id' => $this->branchA->id, 'year' => 2026, 'month' => 7, 'target_amount' => 1000,
        ]);
        \App\Models\BranchRevenueTarget::create([
            'branch_id' => $this->branchB->id, 'year' => 2026, 'month' => 7, 'target_amount' => 2000,
        ]);

        ['user' => $manager] = isolatedStaff(RoleEnum::Manager->value, [$this->branchA]);

        $rows = $this->actingAs($manager)
            ->getJson('/v1/admin/analytics/revenue-targets?year=2026&month=7')
            ->assertSuccessful()
            ->json('data');

        expect(collect($rows)->pluck('branch_id')->all())->toBe([$this->branchA->id]);
    });

    it('scopes the dashboard branch list and live orders', function () {
        ['user' => $manager] = isolatedStaff(RoleEnum::Manager->value, [$this->branchA]);

        $data = $this->actingAs($manager)
            ->getJson('/v1/admin/dashboard')
            ->assertSuccessful()
            ->json('data');

        expect(collect($data['branches'])->pluck('id')->all())->toBe([$this->branchA->id])
            ->and(collect($data['live_orders'])->pluck('branch')->unique()->all())
            ->not->toContain($this->branchB->name);
    });

    it('scopes the payment ledger', function () {
        ['user' => $manager] = isolatedStaff(RoleEnum::Manager->value, [$this->branchA]);

        $rows = $this->actingAs($manager)
            ->getJson('/v1/admin/payments')
            ->assertSuccessful()
            ->json('data.data');

        expect($rows)->toHaveCount(1);
    });
});

/*
|--------------------------------------------------------------------------
| An order belongs to its customer and its branch. Nobody else.
|--------------------------------------------------------------------------
*/

describe('reading a single order', function () {
    it('lets the customer who placed it read it', function () {
        $user = User::factory()->create(['password' => null]);
        $customer = Customer::factory()->create(['user_id' => $user->id]);
        $order = paidOrderAt($this->branchA, $customer);

        $this->actingAs($user->fresh())
            ->getJson("/v1/orders/{$order->id}")
            ->assertSuccessful();
    });

    it('hides another customer\'s order', function () {
        $mine = User::factory()->create(['password' => null]);
        Customer::factory()->create(['user_id' => $mine->id]);

        $theirs = Customer::factory()->create();
        $order = paidOrderAt($this->branchA, $theirs);

        $this->actingAs($mine->fresh())
            ->getJson("/v1/orders/{$order->id}")
            ->assertNotFound();
    });

    it('lets staff read an order at their own branch', function () {
        ['user' => $cashier] = isolatedStaff(RoleEnum::SalesStaff->value, [$this->branchA]);
        $order = paidOrderAt($this->branchA);

        $this->actingAs($cashier)
            ->getJson("/v1/orders/{$order->id}")
            ->assertSuccessful();
    });

    it('hides an order belonging to another branch', function () {
        ['user' => $cashier] = isolatedStaff(RoleEnum::SalesStaff->value, [$this->branchA]);
        $order = paidOrderAt($this->branchB);

        $this->actingAs($cashier)
            ->getJson("/v1/orders/{$order->id}")
            ->assertNotFound();
    });

    it('lets the admin read anything', function () {
        ['user' => $admin] = isolatedStaff(RoleEnum::Admin->value);
        $order = paidOrderAt($this->branchB);

        $this->actingAs($admin)
            ->getJson("/v1/orders/{$order->id}")
            ->assertSuccessful();
    });
});

/*
|--------------------------------------------------------------------------
| Writing to another branch's order
|--------------------------------------------------------------------------
*/

describe('updating an order', function () {
    it('refuses staff at another branch', function () {
        ['user' => $cashier] = isolatedStaff(RoleEnum::SalesStaff->value, [$this->branchA]);
        $order = paidOrderAt($this->branchB);

        $this->actingAs($cashier)
            ->patchJson("/v1/orders/{$order->id}", ['contact_name' => 'Hijacked'])
            ->assertForbidden();

        expect($order->fresh()->contact_name)->not->toBe('Hijacked');
    });

    it('allows staff at the owning branch', function () {
        ['user' => $cashier] = isolatedStaff(RoleEnum::SalesStaff->value, [$this->branchA]);
        $order = paidOrderAt($this->branchA);

        $this->actingAs($cashier)
            ->patchJson("/v1/orders/{$order->id}", ['contact_name' => 'Ama'])
            ->assertSuccessful();

        expect($order->fresh()->contact_name)->toBe('Ama');
    });
});

/*
|--------------------------------------------------------------------------
| Kitchen display
|--------------------------------------------------------------------------
*/

describe('kitchen feed', function () {
    beforeEach(function () {
        Order::factory()->create(['branch_id' => $this->branchA->id, 'status' => 'preparing'])
            ->payments()->create(['payment_status' => 'completed', 'amount' => 10, 'payment_method' => 'cash']);
        Order::factory()->create(['branch_id' => $this->branchB->id, 'status' => 'preparing'])
            ->payments()->create(['payment_status' => 'completed', 'amount' => 10, 'payment_method' => 'cash']);
    });

    it('shows only the caller\'s branch when no branch is named', function () {
        ['user' => $cook] = isolatedStaff(RoleEnum::Kitchen->value, [$this->branchA]);

        $rows = $this->actingAs($cook)
            ->getJson('/v1/kitchen/orders')
            ->assertSuccessful()
            ->json('data');

        expect(collect($rows)->pluck('branch.id')->unique()->all())->toBe([$this->branchA->id]);
    });

    it('refuses a branch the cook does not work at', function () {
        ['user' => $cook] = isolatedStaff(RoleEnum::Kitchen->value, [$this->branchA]);

        $this->actingAs($cook)
            ->getJson("/v1/kitchen/orders?branch_id={$this->branchB->id}")
            ->assertForbidden();
    });
});

/*
|--------------------------------------------------------------------------
| The public branch list is menu and hours, not money
|--------------------------------------------------------------------------
*/

describe('public branch list', function () {
    it('does not publish today\'s takings to the world', function () {
        paidOrderAt($this->branchA);

        $rows = $this->getJson('/v1/branches')
            ->assertSuccessful()
            ->json('data');

        expect($rows)->not->toBeEmpty();

        foreach ($rows as $row) {
            expect($row)->not->toHaveKey('today_revenue')
                ->and($row)->not->toHaveKey('today_orders');
        }
    });

    it('still publishes what a customer needs to choose a branch', function () {
        $row = $this->getJson('/v1/branches')->assertSuccessful()->json('data.0');

        expect($row)->toHaveKeys(['id', 'name', 'address', 'is_open']);
    });

    it('gives the figures to an admin', function () {
        paidOrderAt($this->branchA);
        ['user' => $admin] = isolatedStaff(RoleEnum::Admin->value);

        $rows = $this->actingAs($admin)
            ->getJson('/v1/admin/branches')
            ->assertSuccessful()
            ->json('data');

        expect($rows[0])->toHaveKey('today_revenue');
    });
});

/*
|--------------------------------------------------------------------------
| Staff notes — the manager's one remaining power over his people
|--------------------------------------------------------------------------
*/

describe('staff notes', function () {
    it('lets a manager write and read a note on his own staff', function () {
        ['user' => $manager] = isolatedStaff(RoleEnum::Manager->value, [$this->branchA]);
        ['employee' => $cashier] = isolatedStaff(RoleEnum::SalesStaff->value, [$this->branchA]);

        $this->actingAs($manager)
            ->postJson("/v1/admin/employees/{$cashier->id}/notes", ['content' => 'Late twice this week.'])
            ->assertCreated();

        $notes = $this->actingAs($manager)
            ->getJson("/v1/admin/employees/{$cashier->id}/notes")
            ->assertSuccessful()
            ->json('data');

        expect($notes)->toHaveCount(1)
            ->and($notes[0]['content'])->toBe('Late twice this week.');
    });

    it('lets a manager edit and delete his own note', function () {
        ['user' => $manager] = isolatedStaff(RoleEnum::Manager->value, [$this->branchA]);
        ['employee' => $cashier] = isolatedStaff(RoleEnum::SalesStaff->value, [$this->branchA]);

        $noteId = $this->actingAs($manager)
            ->postJson("/v1/admin/employees/{$cashier->id}/notes", ['content' => 'First draft.'])
            ->json('data.id');

        $this->actingAs($manager)
            ->patchJson("/v1/admin/employees/{$cashier->id}/notes/{$noteId}", ['content' => 'Corrected.'])
            ->assertSuccessful();

        expect(\App\Models\EmployeeNote::find($noteId)->content)->toBe('Corrected.');

        $this->actingAs($manager)
            ->deleteJson("/v1/admin/employees/{$cashier->id}/notes/{$noteId}")
            ->assertSuccessful();

        expect(\App\Models\EmployeeNote::find($noteId))->toBeNull();
    });

    it('will not let one manager rewrite another\'s note', function () {
        ['user' => $author] = isolatedStaff(RoleEnum::Manager->value, [$this->branchA]);
        ['user' => $other] = isolatedStaff(RoleEnum::Manager->value, [$this->branchA]);
        ['employee' => $cashier] = isolatedStaff(RoleEnum::SalesStaff->value, [$this->branchA]);

        $noteId = $this->actingAs($author)
            ->postJson("/v1/admin/employees/{$cashier->id}/notes", ['content' => 'Mine.'])
            ->json('data.id');

        $this->actingAs($other)
            ->patchJson("/v1/admin/employees/{$cashier->id}/notes/{$noteId}", ['content' => 'Not yours.'])
            ->assertForbidden();

        $this->actingAs($other)
            ->deleteJson("/v1/admin/employees/{$cashier->id}/notes/{$noteId}")
            ->assertForbidden();

        expect(\App\Models\EmployeeNote::find($noteId)->content)->toBe('Mine.');
    });

    it('hides staff at another branch entirely', function () {
        ['user' => $manager] = isolatedStaff(RoleEnum::Manager->value, [$this->branchA]);
        ['employee' => $stranger] = isolatedStaff(RoleEnum::SalesStaff->value, [$this->branchB]);

        $this->actingAs($manager)
            ->getJson("/v1/admin/employees/{$stranger->id}/notes")
            ->assertNotFound();

        $this->actingAs($manager)
            ->postJson("/v1/admin/employees/{$stranger->id}/notes", ['content' => 'Reaching.'])
            ->assertNotFound();
    });

    it('refuses a cashier the notes surface altogether', function () {
        ['user' => $cashier] = isolatedStaff(RoleEnum::SalesStaff->value, [$this->branchA]);
        ['employee' => $colleague] = isolatedStaff(RoleEnum::SalesStaff->value, [$this->branchA]);

        $this->actingAs($cashier)
            ->getJson("/v1/admin/employees/{$colleague->id}/notes")
            ->assertForbidden();
    });

    it('scopes the staff roster to the manager\'s branches', function () {
        ['user' => $manager] = isolatedStaff(RoleEnum::Manager->value, [$this->branchA]);
        isolatedStaff(RoleEnum::SalesStaff->value, [$this->branchB]);

        $rows = $this->actingAs($manager)
            ->getJson('/v1/admin/employees')
            ->assertSuccessful()
            ->json('data.data');

        $branchIds = collect($rows)->pluck('branch_ids')->flatten()->unique()->all();

        expect($branchIds)->not->toContain($this->branchB->id);
    });
});

/*
|--------------------------------------------------------------------------
| The guard itself
|--------------------------------------------------------------------------
*/

describe('branch.access middleware', function () {
    it('lets a manager into their own branch', function () {
        ['user' => $manager] = isolatedStaff(RoleEnum::Manager->value, [$this->branchA]);

        $this->actingAs($manager)
            ->getJson("/v1/manager/branches/{$this->branchA->id}/stats")
            ->assertSuccessful();
    });

    it('keeps a manager out of another branch', function () {
        ['user' => $manager] = isolatedStaff(RoleEnum::Manager->value, [$this->branchA]);

        $this->actingAs($manager)
            ->getJson("/v1/manager/branches/{$this->branchB->id}/stats")
            ->assertForbidden();
    });

    it('fails closed when the route binds no branch', function () {
        // The guard can only compare a bound {branch}. On any other route it has
        // nothing to check, and used to wave the request through — so putting it
        // on /employees/{employee} silently guarded nothing.
        $middleware = new \App\Http\Middleware\EnsureBranchAccess;

        ['user' => $manager] = isolatedStaff(RoleEnum::Manager->value, [$this->branchA]);

        $request = \Illuminate\Http\Request::create('/v1/whatever', 'GET');
        $request->setUserResolver(fn () => $manager);

        $response = $middleware->handle($request, fn () => response()->json(['reached' => true]));

        expect($response->getStatusCode())->toBe(403);
    });
});
