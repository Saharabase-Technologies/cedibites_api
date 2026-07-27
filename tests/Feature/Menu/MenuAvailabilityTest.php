<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
|--------------------------------------------------------------------------
| "We've run out of Jollof today."
|--------------------------------------------------------------------------
|
| The branch manager's only menu power. He takes a dish off his own branch and
| puts it back. He cannot rename it, reprice it, create it or delete it — the
| menu is one menu across every branch, so all of that is the Admin's, and so
| are per-branch prices.
|
*/

/**
 * @return array{user: User, employee: Employee}
 */
function availabilityStaff(string $role, ?Branch $branch = null): array
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

    $this->branch = Branch::factory()->create(['name' => 'Ashaiman']);
    $this->other = Branch::factory()->create(['name' => 'Kasoa']);

    $this->dish = MenuItem::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Jollof Rice',
        'is_available' => true,
    ]);
    $this->dish->branches()->attach($this->branch->id, ['is_available' => true]);
});

describe('a manager at his own branch', function () {
    it('sees what his branch serves', function () {
        ['user' => $manager] = availabilityStaff(RoleEnum::Manager->value, $this->branch);

        $rows = $this->actingAs($manager)
            ->getJson("/v1/manager/branches/{$this->branch->id}/menu-availability")
            ->assertSuccessful()
            ->json('data');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['name'])->toBe('Jollof Rice')
            ->and($rows[0]['available_here'])->toBeTrue();
    });

    it('can mark a dish sold out', function () {
        ['user' => $manager] = availabilityStaff(RoleEnum::Manager->value, $this->branch);

        $this->actingAs($manager)
            ->patchJson("/v1/manager/branches/{$this->branch->id}/menu-availability/{$this->dish->id}", [
                'is_available' => false,
            ])
            ->assertSuccessful();

        expect((bool) $this->dish->fresh()->branches->first()->pivot->is_available)->toBeFalse()
            // Sold out here, still on the menu everywhere else.
            ->and((bool) $this->dish->fresh()->is_available)->toBeTrue();
    });

    it('can put it back on', function () {
        ['user' => $manager] = availabilityStaff(RoleEnum::Manager->value, $this->branch);
        $this->dish->branches()->syncWithoutDetaching([$this->branch->id => ['is_available' => false]]);

        $this->actingAs($manager)
            ->patchJson("/v1/manager/branches/{$this->branch->id}/menu-availability/{$this->dish->id}", [
                'is_available' => true,
            ])
            ->assertSuccessful();

        expect((bool) $this->dish->fresh()->branches->first()->pivot->is_available)->toBeTrue();
    });

    it('does not affect another branch', function () {
        ['user' => $manager] = availabilityStaff(RoleEnum::Manager->value, $this->branch);
        $this->dish->branches()->attach($this->other->id, ['is_available' => true]);

        $this->actingAs($manager)
            ->patchJson("/v1/manager/branches/{$this->branch->id}/menu-availability/{$this->dish->id}", [
                'is_available' => false,
            ])
            ->assertSuccessful();

        $atOther = $this->dish->fresh()->branches->firstWhere('id', $this->other->id);

        expect((bool) $atOther->pivot->is_available)->toBeTrue();
    });
});

describe('what a manager cannot do', function () {
    it('cannot touch another branch', function () {
        ['user' => $manager] = availabilityStaff(RoleEnum::Manager->value, $this->branch);

        $this->actingAs($manager)
            ->getJson("/v1/manager/branches/{$this->other->id}/menu-availability")
            ->assertForbidden();
    });

    it('cannot reprice through this endpoint', function () {
        ['user' => $manager] = availabilityStaff(RoleEnum::Manager->value, $this->branch);
        $option = $this->dish->options->first();
        $originalPrice = (float) $option->price;

        $this->actingAs($manager)
            ->patchJson("/v1/manager/branches/{$this->branch->id}/menu-availability/{$this->dish->id}", [
                'is_available' => true,
                'price' => 1.00,
            ])
            ->assertSuccessful();

        expect((float) $option->fresh()->price)->toBe($originalPrice);
    });

    it('cannot rename the dish', function () {
        ['user' => $manager] = availabilityStaff(RoleEnum::Manager->value, $this->branch);

        $this->actingAs($manager)
            ->patchJson("/v1/admin/menu-items/{$this->dish->id}", ['name' => 'Renamed'])
            ->assertForbidden();

        expect($this->dish->fresh()->name)->toBe('Jollof Rice');
    });
});

describe('who else may use it', function () {
    it('refuses a cashier', function () {
        ['user' => $cashier] = availabilityStaff(RoleEnum::SalesStaff->value, $this->branch);

        $this->actingAs($cashier)
            ->getJson("/v1/manager/branches/{$this->branch->id}/menu-availability")
            ->assertForbidden();
    });

    it('lets an admin set it at any branch', function () {
        ['user' => $admin] = availabilityStaff(RoleEnum::Admin->value);

        $this->actingAs($admin)
            ->patchJson("/v1/manager/branches/{$this->branch->id}/menu-availability/{$this->dish->id}", [
                'is_available' => false,
            ])
            ->assertSuccessful();

        expect((bool) $this->dish->fresh()->branches->first()->pivot->is_available)->toBeFalse();
    });

    it('refuses a dish the branch does not serve', function () {
        ['user' => $manager] = availabilityStaff(RoleEnum::Manager->value, $this->branch);
        $elsewhere = MenuItem::factory()->create(['branch_id' => $this->other->id]);
        $elsewhere->branches()->attach($this->other->id, ['is_available' => true]);

        $this->actingAs($manager)
            ->patchJson("/v1/manager/branches/{$this->branch->id}/menu-availability/{$elsewhere->id}", [
                'is_available' => false,
            ])
            ->assertNotFound();
    });
});
