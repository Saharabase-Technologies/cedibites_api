<?php

use App\Enums\EmployeeStatus;
use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\ManagerScopeCleanupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Branch Isolation, Phase 0 — the manager's ceiling
|--------------------------------------------------------------------------
|
| A branch manager runs a branch, not the business. Every branch is the same
| institution behind a different till, so the menu, the prices and the staff
| roster are company-level and belong to the Admin.
|
| The escalation these tests pin down: `manage_employees` gates
| PATCH /admin/employees/{employee}, whose request accepts any value from the
| Role enum with no ceiling check and no self-edit guard. A manager holding it
| could promote himself to tech_admin in one call.
|
*/

/**
 * A user + employee with a role, optionally attached to a branch.
 *
 * @return array{user: User, employee: Employee}
 */
function scopedStaff(string $role, ?Branch $branch = null): array
{
    $user = User::factory()->create();
    $employee = Employee::factory()->create([
        'user_id' => $user->id,
        'status' => EmployeeStatus::Active,
    ]);

    if ($branch) {
        $employee->branches()->attach($branch);
    }

    $user->assignRole($role);

    return ['user' => $user->fresh(), 'employee' => $employee];
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

/*
|--------------------------------------------------------------------------
| The permission matrix
|--------------------------------------------------------------------------
*/

describe('manager permission matrix', function () {
    it('does not grant the manager any company-level power', function (string $permission) {
        ['user' => $manager] = scopedStaff(RoleEnum::Manager->value);

        expect($manager->can($permission))->toBeFalse();
    })->with([
        'manage_menu' => Permission::ManageMenu->value,
        'manage_employees' => Permission::ManageEmployees->value,
        'manage_branches' => Permission::ManageBranches->value,
        'delete_orders' => Permission::DeleteOrders->value,
    ]);

    it('grants the manager the narrow replacements', function (string $permission) {
        ['user' => $manager] = scopedStaff(RoleEnum::Manager->value);

        expect($manager->can($permission))->toBeTrue();
    })->with([
        'menu availability' => Permission::MenuAvailabilityManage->value,
        'employee notes' => Permission::EmployeeNotesManage->value,
        'branch operate' => Permission::BranchOperate->value,
    ]);

    it('leaves the manager everything a shift actually needs', function (string $permission) {
        ['user' => $manager] = scopedStaff(RoleEnum::Manager->value);

        expect($manager->can($permission))->toBeTrue();
    })->with([
        'view orders' => Permission::ViewOrders->value,
        'create orders' => Permission::CreateOrders->value,
        'update orders' => Permission::UpdateOrders->value,
        'view menu' => Permission::ViewMenu->value,
        'view branches' => Permission::ViewBranches->value,
        'view employees' => Permission::ViewEmployees->value,
        'view analytics' => Permission::ViewAnalytics->value,
        'manage shifts' => Permission::ManageShifts->value,
        'access pos' => Permission::AccessPos->value,
        'access manager portal' => Permission::AccessManagerPortal->value,
    ]);

    it('still gives admins the new grants', function (string $role) {
        ['user' => $user] = scopedStaff($role);

        expect($user->can(Permission::MenuAvailabilityManage->value))->toBeTrue()
            ->and($user->can(Permission::EmployeeNotesManage->value))->toBeTrue()
            ->and($user->can(Permission::BranchOperate->value))->toBeTrue();
    })->with([
        'admin' => RoleEnum::Admin->value,
        'tech_admin' => RoleEnum::TechAdmin->value,
    ]);

    it('never let the manager reach the admin panel', function () {
        ['user' => $manager] = scopedStaff(RoleEnum::Manager->value);

        expect($manager->can(Permission::AccessAdminPanel->value))->toBeFalse();
    });
});

/*
|--------------------------------------------------------------------------
| The escalation path is closed
|--------------------------------------------------------------------------
*/

describe('manager cannot escalate', function () {
    it('cannot promote itself to tech_admin', function () {
        $branch = Branch::factory()->create();
        ['user' => $manager, 'employee' => $employee] = scopedStaff(RoleEnum::Manager->value, $branch);

        $this->actingAs($manager)
            ->patchJson("/v1/admin/employees/{$employee->id}", [
                'role' => RoleEnum::TechAdmin->value,
            ])
            ->assertForbidden();

        expect($manager->fresh()->hasRole(RoleEnum::TechAdmin->value))->toBeFalse()
            ->and($manager->fresh()->hasRole(RoleEnum::Manager->value))->toBeTrue();
    });

    it('cannot grant itself a permission directly', function () {
        $branch = Branch::factory()->create();
        ['user' => $manager, 'employee' => $employee] = scopedStaff(RoleEnum::Manager->value, $branch);

        $this->actingAs($manager)
            ->patchJson("/v1/admin/employees/{$employee->id}", [
                'permissions' => [Permission::AccessPlatformAdmin->value],
            ])
            ->assertForbidden();

        expect($manager->fresh()->can(Permission::AccessPlatformAdmin->value))->toBeFalse();
    });

    it('cannot promote someone else', function () {
        $branch = Branch::factory()->create();
        ['user' => $manager] = scopedStaff(RoleEnum::Manager->value, $branch);
        ['user' => $cashier, 'employee' => $cashierEmployee] = scopedStaff(RoleEnum::SalesStaff->value, $branch);

        $this->actingAs($manager)
            ->patchJson("/v1/admin/employees/{$cashierEmployee->id}", [
                'role' => RoleEnum::Admin->value,
            ])
            ->assertForbidden();

        expect($cashier->fresh()->hasRole(RoleEnum::Admin->value))->toBeFalse();
    });

    it('cannot hire', function () {
        ['user' => $manager] = scopedStaff(RoleEnum::Manager->value);

        $this->actingAs($manager)
            ->postJson('/v1/admin/employees', [
                'name' => 'New Hire',
                'phone' => '+233541234567',
                'role' => RoleEnum::SalesStaff->value,
                'branch_ids' => [Branch::factory()->create()->id],
            ])
            ->assertForbidden();
    });

    it('cannot force another user to log out', function () {
        $branch = Branch::factory()->create();
        ['user' => $manager] = scopedStaff(RoleEnum::Manager->value, $branch);
        ['employee' => $cashierEmployee] = scopedStaff(RoleEnum::SalesStaff->value, $branch);

        $this->actingAs($manager)
            ->postJson("/v1/admin/employees/{$cashierEmployee->id}/force-logout")
            ->assertForbidden();
    });
});

/*
|--------------------------------------------------------------------------
| Menu, branches and orders are the Admin's
|--------------------------------------------------------------------------
*/

describe('manager cannot reach company-level data', function () {
    it('cannot create a menu item', function () {
        ['user' => $manager] = scopedStaff(RoleEnum::Manager->value);

        $this->actingAs($manager)
            ->postJson('/v1/admin/menu-items', [
                'branch_id' => Branch::factory()->create()->id,
                'name' => 'Contraband Jollof',
            ])
            ->assertForbidden();
    });

    it('cannot edit a menu item', function () {
        $branch = Branch::factory()->create();
        ['user' => $manager] = scopedStaff(RoleEnum::Manager->value, $branch);
        $item = MenuItem::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($manager)
            ->patchJson("/v1/admin/menu-items/{$item->id}", ['name' => 'Renamed'])
            ->assertForbidden();

        expect($item->fresh()->name)->not->toBe('Renamed');
    });

    it('cannot change a branch price override', function () {
        $branch = Branch::factory()->create();
        ['user' => $manager] = scopedStaff(RoleEnum::Manager->value, $branch);
        $item = MenuItem::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($manager)
            ->putJson("/v1/admin/menu-items/{$item->id}/branch-options", [
                'branches' => [
                    (string) $branch->id => [
                        'options' => [['option_key' => 'standard', 'price' => 1.00]],
                    ],
                ],
            ])
            ->assertForbidden();
    });

    it('cannot create a branch', function () {
        ['user' => $manager] = scopedStaff(RoleEnum::Manager->value);

        $this->actingAs($manager)
            ->postJson('/v1/admin/branches', ['name' => 'Rogue Branch'])
            ->assertForbidden();
    });

    it('cannot delete its own branch', function () {
        $branch = Branch::factory()->create();
        ['user' => $manager] = scopedStaff(RoleEnum::Manager->value, $branch);

        $this->actingAs($manager)
            ->deleteJson("/v1/admin/branches/{$branch->id}")
            ->assertForbidden();

        expect(Branch::find($branch->id))->not->toBeNull();
    });

    it('cannot delete an order', function () {
        $branch = Branch::factory()->create();
        ['user' => $manager] = scopedStaff(RoleEnum::Manager->value, $branch);
        $order = Order::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($manager)
            ->deleteJson("/v1/orders/{$order->id}")
            ->assertForbidden();

        expect(Order::find($order->id))->not->toBeNull();
    });
});

/*
|--------------------------------------------------------------------------
| No regression for the Admin
|--------------------------------------------------------------------------
*/

describe('admin is unaffected', function () {
    it('can still change an employee role', function () {
        $branch = Branch::factory()->create();
        ['user' => $admin] = scopedStaff(RoleEnum::Admin->value, $branch);
        ['user' => $cashier, 'employee' => $cashierEmployee] = scopedStaff(RoleEnum::SalesStaff->value, $branch);

        $this->actingAs($admin)
            ->patchJson("/v1/admin/employees/{$cashierEmployee->id}", [
                'role' => RoleEnum::Kitchen->value,
            ])
            ->assertSuccessful();

        expect($cashier->fresh()->hasRole(RoleEnum::Kitchen->value))->toBeTrue();
    });

    it('can still edit a menu item', function () {
        $branch = Branch::factory()->create();
        ['user' => $admin] = scopedStaff(RoleEnum::Admin->value, $branch);
        $item = MenuItem::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($admin)
            ->patchJson("/v1/admin/menu-items/{$item->id}", ['name' => 'Renamed by admin'])
            ->assertSuccessful();

        expect($item->fresh()->name)->toBe('Renamed by admin');
    });
});

/*
|--------------------------------------------------------------------------
| ManagerScopeCleanupSeeder — prod and beta were seeded before this scoping
|--------------------------------------------------------------------------
*/

describe('ManagerScopeCleanupSeeder', function () {
    it('revokes the stale grants RoleSeeder cannot remove', function () {
        $role = Role::where('name', RoleEnum::Manager->value)->where('guard_name', 'api')->first();

        // Put the environment back the way prod was seeded.
        $role->givePermissionTo([
            Permission::ManageMenu->value,
            Permission::ManageEmployees->value,
            Permission::ManageBranches->value,
            Permission::DeleteOrders->value,
        ]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        ['user' => $manager] = scopedStaff(RoleEnum::Manager->value);
        expect($manager->can(Permission::ManageEmployees->value))->toBeTrue();

        $this->seed(ManagerScopeCleanupSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $manager = $manager->fresh();
        expect($manager->can(Permission::ManageEmployees->value))->toBeFalse()
            ->and($manager->can(Permission::ManageMenu->value))->toBeFalse()
            ->and($manager->can(Permission::ManageBranches->value))->toBeFalse()
            ->and($manager->can(Permission::DeleteOrders->value))->toBeFalse()
            ->and($manager->can(Permission::EmployeeNotesManage->value))->toBeTrue();
    });

    it('strips a grant attached straight to the user, not just the role', function () {
        ['user' => $manager] = scopedStaff(RoleEnum::Manager->value);

        // EmployeeController::update has been syncing arbitrary permission arrays
        // onto users. A direct grant outlives a role revoke.
        $manager->givePermissionTo(Permission::ManageEmployees->value);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        expect($manager->fresh()->can(Permission::ManageEmployees->value))->toBeTrue();

        $this->seed(ManagerScopeCleanupSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        expect($manager->fresh()->can(Permission::ManageEmployees->value))->toBeFalse();
    });

    it('is safe to run twice', function () {
        ['user' => $manager] = scopedStaff(RoleEnum::Manager->value);

        $this->seed(ManagerScopeCleanupSeeder::class);
        $this->seed(ManagerScopeCleanupSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $manager = $manager->fresh();
        expect($manager->can(Permission::ManageEmployees->value))->toBeFalse()
            ->and($manager->can(Permission::EmployeeNotesManage->value))->toBeTrue()
            ->and($manager->can(Permission::ViewOrders->value))->toBeTrue();
    });
});
