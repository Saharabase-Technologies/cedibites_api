<?php

use App\Enums\BranchRule;
use App\Enums\EmployeeStatus;
use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| The role rules
|--------------------------------------------------------------------------
|
| A staff account is four things at once — a role, the permissions that role
| carries, an employment status, and a branch — and each of them was being
| decided somewhere different. These pin down the single set of answers:
|
|   · tech_admin is never handed out by the staff editor.
|   · Nobody changes their own role.
|   · One role per user, always.
|   · Permissions come from the role and from nowhere else.
|   · How many branches a role takes is the role's business, not the form's.
|   · Anything other than Active means no access, starting immediately.
|
*/

function ruleStaff(string $role, ?Branch $branch = null): array
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

beforeEach(function () {
    // Hiring sends the new staff member their password by SMS. That is a real
    // outbound call to Hubtel, which no test should be making.
    Notification::fake();

    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->create();
    ['user' => $this->admin] = ruleStaff(RoleEnum::Admin->value);
});

/*
|--------------------------------------------------------------------------
| The tech_admin ceiling
|--------------------------------------------------------------------------
*/

describe('tech_admin is not the staff editor\'s to give', function () {
    it('refuses to hire one', function () {
        $this->actingAs($this->admin)
            ->postJson('/v1/admin/employees', [
                'name' => 'Backdoor',
                'phone' => '+233541110000',
                'role' => RoleEnum::TechAdmin->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        expect(User::role(RoleEnum::TechAdmin->value)->count())->toBe(0);
    });

    it('refuses to promote someone else to one', function () {
        ['user' => $cashier, 'employee' => $employee] = ruleStaff(RoleEnum::SalesStaff->value, $this->branch);

        $this->actingAs($this->admin)
            ->patchJson("/v1/admin/employees/{$employee->id}", ['role' => RoleEnum::TechAdmin->value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        expect($cashier->fresh()->hasRole(RoleEnum::TechAdmin->value))->toBeFalse();
    });

    it('refuses to let an admin promote themselves at all', function () {
        $adminEmployee = $this->admin->employee;

        $this->actingAs($this->admin)
            ->patchJson("/v1/admin/employees/{$adminEmployee->id}", ['role' => RoleEnum::TechAdmin->value])
            ->assertForbidden();

        expect($this->admin->fresh()->hasRole(RoleEnum::TechAdmin->value))->toBeFalse()
            ->and($this->admin->fresh()->can(Permission::AccessPlatformAdmin->value))->toBeFalse();
    });

    it('refuses a self-role-change even to a lesser role', function () {
        $adminEmployee = $this->admin->employee;

        $this->actingAs($this->admin)
            ->patchJson("/v1/admin/employees/{$adminEmployee->id}", ['role' => RoleEnum::Manager->value])
            ->assertForbidden();

        expect($this->admin->fresh()->hasRole(RoleEnum::Admin->value))->toBeTrue();
    });

    it('lets an admin edit their own profile as long as the role is left alone', function () {
        $adminEmployee = $this->admin->employee;

        $this->actingAs($this->admin)
            ->patchJson("/v1/admin/employees/{$adminEmployee->id}", ['name' => 'Renamed Admin'])
            ->assertSuccessful();

        expect($this->admin->fresh()->name)->toBe('Renamed Admin');
    });

    it('refuses an admin any edit at all to a tech_admin account', function () {
        ['user' => $techAdmin, 'employee' => $employee] = ruleStaff(RoleEnum::TechAdmin->value);

        $this->actingAs($this->admin)
            ->patchJson("/v1/admin/employees/{$employee->id}", ['status' => EmployeeStatus::Suspended->value])
            ->assertForbidden();

        expect($employee->fresh()->status)->toBe(EmployeeStatus::Active)
            ->and($techAdmin->fresh()->hasRole(RoleEnum::TechAdmin->value))->toBeTrue();
    });

    it('lets a tech_admin edit a tech_admin', function () {
        ['user' => $actor] = ruleStaff(RoleEnum::TechAdmin->value);
        ['employee' => $target] = ruleStaff(RoleEnum::TechAdmin->value);

        $this->actingAs($actor)
            ->patchJson("/v1/admin/employees/{$target->id}", ['name' => 'Still Fine'])
            ->assertSuccessful();
    });
});

/*
|--------------------------------------------------------------------------
| Permissions come from the role
|--------------------------------------------------------------------------
*/

describe('the staff editor grants no permissions', function () {
    it('ignores a permissions payload on hire', function () {
        $this->actingAs($this->admin)
            ->postJson('/v1/admin/employees', [
                'name' => 'New Cashier',
                'phone' => '+233541110001',
                'role' => RoleEnum::SalesStaff->value,
                'branch_ids' => [$this->branch->id],
                'permissions' => [Permission::ManageEmployees->value, Permission::ManageMenu->value],
            ])
            ->assertCreated();

        $user = User::where('phone', '+233541110001')->first();

        expect($user->permissions)->toBeEmpty()
            ->and($user->can(Permission::ManageEmployees->value))->toBeFalse()
            ->and($user->can(Permission::ManageMenu->value))->toBeFalse();
    });

    it('ignores a permissions payload on edit', function () {
        ['user' => $manager, 'employee' => $employee] = ruleStaff(RoleEnum::Manager->value, $this->branch);

        $this->actingAs($this->admin)
            ->patchJson("/v1/admin/employees/{$employee->id}", [
                'permissions' => [Permission::ManageEmployees->value, Permission::ManageBranches->value],
            ])
            ->assertSuccessful();

        expect($manager->fresh()->can(Permission::ManageEmployees->value))->toBeFalse()
            ->and($manager->fresh()->can(Permission::ManageBranches->value))->toBeFalse();
    });

    /**
     * The regression that mattered: the old editor read a manager's effective
     * permissions, showed them as checkboxes and wrote the lot back as direct
     * grants, handing back exactly what the manager ceiling had removed.
     */
    it('clears a direct grant that predates the rules on the next edit', function () {
        ['user' => $manager, 'employee' => $employee] = ruleStaff(RoleEnum::Manager->value, $this->branch);
        $manager->givePermissionTo(Permission::ManageEmployees->value);

        expect($manager->fresh()->can(Permission::ManageEmployees->value))->toBeTrue();

        $this->actingAs($this->admin)
            ->patchJson("/v1/admin/employees/{$employee->id}", ['name' => 'Any Edit At All'])
            ->assertSuccessful();

        expect($manager->fresh()->can(Permission::ManageEmployees->value))->toBeFalse();
    });
});

/*
|--------------------------------------------------------------------------
| One role per user
|--------------------------------------------------------------------------
*/

describe('one role per user', function () {
    it('replaces the role rather than adding to it', function () {
        ['user' => $staff, 'employee' => $employee] = ruleStaff(RoleEnum::SalesStaff->value, $this->branch);

        $this->actingAs($this->admin)
            ->patchJson("/v1/admin/employees/{$employee->id}", ['role' => RoleEnum::Kitchen->value])
            ->assertSuccessful();

        expect($staff->fresh()->getRoleNames()->all())->toBe([RoleEnum::Kitchen->value]);
    });

    it('does not leave a former role attached when an existing user is hired', function () {
        $existing = User::factory()->create(['phone' => '+233541110002']);
        $existing->syncRoles([RoleEnum::Kitchen->value]);

        $this->actingAs($this->admin)
            ->postJson('/v1/admin/employees', [
                'name' => 'Rehired',
                'phone' => '+233541110002',
                'role' => RoleEnum::Rider->value,
                'branch_ids' => [$this->branch->id],
            ])
            ->assertCreated();

        expect($existing->fresh()->getRoleNames()->all())->toBe([RoleEnum::Rider->value]);
    });
});

/*
|--------------------------------------------------------------------------
| Branch cardinality
|--------------------------------------------------------------------------
*/

describe('how many branches a role takes', function () {
    it('agrees with the enum', function (string $role, BranchRule $expected) {
        expect(RoleEnum::from($role)->branchRule())->toBe($expected);
    })->with([
        'admin' => [RoleEnum::Admin->value, BranchRule::None],
        'tech_admin' => [RoleEnum::TechAdmin->value, BranchRule::None],
        'warehouse_manager' => [RoleEnum::WarehouseManager->value, BranchRule::None],
        'purchasing_clerk' => [RoleEnum::PurchasingClerk->value, BranchRule::None],
        'call_center' => [RoleEnum::CallCenter->value, BranchRule::None],
        'manager' => [RoleEnum::Manager->value, BranchRule::ExactlyOne],
        'sales_staff' => [RoleEnum::SalesStaff->value, BranchRule::ExactlyOne],
        'kitchen' => [RoleEnum::Kitchen->value, BranchRule::ExactlyOne],
        'rider' => [RoleEnum::Rider->value, BranchRule::OneOrMore],
        'branch_partner' => [RoleEnum::BranchPartner->value, BranchRule::OneOrMore],
    ]);

    it('hires a company-wide role with no branch at all', function (string $role) {
        $this->actingAs($this->admin)
            ->postJson('/v1/admin/employees', [
                'name' => 'Head Office',
                'phone' => '+233541110003',
                'role' => $role,
            ])
            ->assertCreated();

        expect(User::where('phone', '+233541110003')->first()->employee->branches)->toBeEmpty();
    })->with([
        RoleEnum::Admin->value,
        RoleEnum::WarehouseManager->value,
        RoleEnum::PurchasingClerk->value,
        RoleEnum::CallCenter->value,
    ]);

    /**
     * Lenient on purpose. A client that still sends a branch for a company-wide
     * role is not making a mistake an operator can correct — the field should
     * not have been on the form — so the branch is dropped and the request
     * succeeds. Refusing would break every older client on every save.
     */
    it('drops a branch sent for a company-wide role instead of refusing it', function () {
        $this->actingAs($this->admin)
            ->postJson('/v1/admin/employees', [
                'name' => 'Warehouse Boss',
                'phone' => '+233541110004',
                'role' => RoleEnum::WarehouseManager->value,
                'branch_ids' => [$this->branch->id],
            ])
            ->assertCreated();

        expect(User::where('phone', '+233541110004')->first()->employee->branches)->toBeEmpty();
    });

    it('clears the branch when a manager becomes an admin', function () {
        ['employee' => $employee] = ruleStaff(RoleEnum::Manager->value, $this->branch);

        expect($employee->branches()->count())->toBe(1);

        $this->actingAs($this->admin)
            ->patchJson("/v1/admin/employees/{$employee->id}", ['role' => RoleEnum::Admin->value])
            ->assertSuccessful();

        expect($employee->fresh()->branches()->count())->toBe(0);
    });

    it('refuses a second branch for a role that runs one', function () {
        $other = Branch::factory()->create();
        ['employee' => $employee] = ruleStaff(RoleEnum::Manager->value, $this->branch);

        $this->actingAs($this->admin)
            ->patchJson("/v1/admin/employees/{$employee->id}", [
                'branch_ids' => [$this->branch->id, $other->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_ids');

        expect($employee->fresh()->branches()->count())->toBe(1);
    });

    it('refuses to hire a manager with two branches', function () {
        $other = Branch::factory()->create();

        $this->actingAs($this->admin)
            ->postJson('/v1/admin/employees', [
                'name' => 'Two Branch Manager',
                'phone' => '+233541110005',
                'role' => RoleEnum::Manager->value,
                'branch_ids' => [$this->branch->id, $other->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_ids');
    });

    it('accepts several branches for a rider', function () {
        $other = Branch::factory()->create();

        $this->actingAs($this->admin)
            ->postJson('/v1/admin/employees', [
                'name' => 'Busy Rider',
                'phone' => '+233541110006',
                'role' => RoleEnum::Rider->value,
                'branch_ids' => [$this->branch->id, $other->id],
            ])
            ->assertCreated();

        expect(User::where('phone', '+233541110006')->first()->employee->branches)->toHaveCount(2);
    });

    /**
     * The case field rules cannot see: the role changes to one that runs a
     * single branch, the caller does not resend branch_ids, and the branches
     * already on the account are not a legal set for the new role.
     */
    it('makes a promotion say which branch when the current set does not fit', function () {
        $other = Branch::factory()->create();
        ['employee' => $employee] = ruleStaff(RoleEnum::Rider->value, $this->branch);
        $employee->branches()->attach($other);

        $this->actingAs($this->admin)
            ->patchJson("/v1/admin/employees/{$employee->id}", ['role' => RoleEnum::Manager->value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_ids');

        $this->actingAs($this->admin)
            ->patchJson("/v1/admin/employees/{$employee->id}", [
                'role' => RoleEnum::Manager->value,
                'branch_ids' => [$this->branch->id],
            ])
            ->assertSuccessful();

        expect($employee->fresh()->branches()->count())->toBe(1);
    });

    it('requires a branch when hiring a role that needs one', function () {
        $this->actingAs($this->admin)
            ->postJson('/v1/admin/employees', [
                'name' => 'Branchless Cashier',
                'phone' => '+233541110007',
                'role' => RoleEnum::SalesStaff->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_ids');
    });
});

/*
|--------------------------------------------------------------------------
| Company-wide means every branch, not none
|--------------------------------------------------------------------------
|
| Taking the branch off the call centre is only half a rule. In the pivot
| table, "not confined to a branch" and "assigned no branches" are the same
| empty set, and every branch-scoped read took the second reading — so a call
| centre agent with no branch would have seen no orders at all and been unable
| to advance any of them.
|
*/

describe('a company-wide role is not confined to a branch', function () {
    it('knows which roles are company-wide', function (string $role, bool $expected) {
        ['user' => $user] = ruleStaff($role, $expected ? null : $this->branch);

        expect($user->isCompanyWide())->toBe($expected);
    })->with([
        'admin' => [RoleEnum::Admin->value, true],
        'tech_admin' => [RoleEnum::TechAdmin->value, true],
        'call_center' => [RoleEnum::CallCenter->value, true],
        'warehouse_manager' => [RoleEnum::WarehouseManager->value, true],
        'purchasing_clerk' => [RoleEnum::PurchasingClerk->value, true],
        'manager' => [RoleEnum::Manager->value, false],
        'sales_staff' => [RoleEnum::SalesStaff->value, false],
        'kitchen' => [RoleEnum::Kitchen->value, false],
        'rider' => [RoleEnum::Rider->value, false],
        'branch_partner' => [RoleEnum::BranchPartner->value, false],
    ]);

    it('shows a branchless call centre agent every branch\'s orders', function () {
        $other = Branch::factory()->create();
        ['user' => $agent] = ruleStaff(RoleEnum::CallCenter->value);
        Order::factory()->create(['branch_id' => $this->branch->id]);
        Order::factory()->create(['branch_id' => $other->id]);

        $response = $this->actingAs($agent)->getJson('/v1/employee/orders')->assertSuccessful();

        expect($response->json('data.data'))->toHaveCount(2);
    });

    it('lets a branchless call centre agent advance an order at any branch', function () {
        ['user' => $agent] = ruleStaff(RoleEnum::CallCenter->value);
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'received',
        ]);

        $this->actingAs($agent)
            ->patchJson("/v1/employee/orders/{$order->id}/status", ['status' => 'accepted'])
            ->assertSuccessful();

        expect($order->fresh()->status)->toBe('accepted');
    });

    it('still confines a cashier to their own branch', function () {
        $other = Branch::factory()->create();
        ['user' => $cashier] = ruleStaff(RoleEnum::SalesStaff->value, $this->branch);
        $order = Order::factory()->create(['branch_id' => $other->id, 'status' => 'received']);

        $this->actingAs($cashier)
            ->patchJson("/v1/employee/orders/{$order->id}/status", ['status' => 'accepted'])
            ->assertForbidden();

        expect($order->fresh()->status)->toBe('received');
    });
});

/*
|--------------------------------------------------------------------------
| Suspension actually suspends
|--------------------------------------------------------------------------
*/

describe('a status that is not active ends access', function () {
    /**
     * One authenticated identity per test: the auth guard caches the user it
     * resolved for the first request and reuses it for the rest of the test,
     * whether that came from actingAs() or from a bearer token. So this proves
     * the tokens are gone, and the middleware test below proves what happens to
     * one that somehow survives. Together they cover the whole path.
     */
    it('revokes the tokens in hand when suspended through an edit', function () {
        ['user' => $cashier, 'employee' => $employee] = ruleStaff(RoleEnum::SalesStaff->value, $this->branch);
        $cashier->createToken('t', ['staff']);
        $cashier->createToken('second-device', ['staff']);

        expect($cashier->fresh()->tokens()->count())->toBe(2);

        $this->actingAs($this->admin)
            ->patchJson("/v1/admin/employees/{$employee->id}", ['status' => EmployeeStatus::Suspended->value])
            ->assertSuccessful();

        expect($employee->fresh()->status)->toBe(EmployeeStatus::Suspended)
            ->and($cashier->fresh()->tokens()->count())->toBe(0);
    });

    it('ends any open shift when suspended through an edit', function () {
        ['user' => $cashier, 'employee' => $employee] = ruleStaff(RoleEnum::SalesStaff->value, $this->branch);
        $shift = Shift::factory()->create([
            'employee_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'logout_at' => null,
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/v1/admin/employees/{$employee->id}", ['status' => EmployeeStatus::Terminated->value])
            ->assertSuccessful();

        expect($shift->fresh()->logout_at)->not->toBeNull();
    });

    it('refuses the staff surface to a token that outlives the suspension', function () {
        ['user' => $cashier, 'employee' => $employee] = ruleStaff(RoleEnum::SalesStaff->value, $this->branch);
        $token = $cashier->createToken('t', ['staff'])->plainTextToken;

        // Suspend without going through the controller, so the token survives —
        // this is the belt to endAccess's braces.
        $employee->update(['status' => EmployeeStatus::Suspended->value]);

        $this->withToken($token)
            ->getJson('/v1/employee/me')
            ->assertForbidden()
            ->assertJsonFragment(['error' => 'staff_account_inactive']);
    });

    it('lets an active employee straight through', function () {
        ['user' => $cashier] = ruleStaff(RoleEnum::SalesStaff->value, $this->branch);
        $token = $cashier->createToken('t', ['staff'])->plainTextToken;

        $this->withToken($token)->getJson('/v1/employee/me')->assertSuccessful();
    });

    it('does not revoke anything when the status is unchanged', function () {
        ['user' => $cashier, 'employee' => $employee] = ruleStaff(RoleEnum::SalesStaff->value, $this->branch);
        $cashier->createToken('t', ['staff']);

        $this->actingAs($this->admin)
            ->patchJson("/v1/admin/employees/{$employee->id}", [
                'name' => 'Renamed',
                'status' => EmployeeStatus::Active->value,
            ])
            ->assertSuccessful();

        expect($cashier->fresh()->tokens()->count())->toBe(1);
    });
});
