<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Inventory\Alert;
use App\Models\Inventory\Location;
use App\Models\User;
use App\Services\BranchProvisioningService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
|--------------------------------------------------------------------------
| Branch Isolation, Phase 2 — a branch is not just a row in `branches`
|--------------------------------------------------------------------------
|
| Without an inventory location a branch is invisible in the IMS to its own
| manager, and its sales fall through to debiting the mother kitchen. Nothing
| created that location: the catalog seeder does the first four branches and
| anything added later silently had none.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

describe('provisioning a branch', function () {
    it('gives a new branch its own satellite location', function () {
        $branch = Branch::factory()->create(['name' => 'Test Branch', 'address' => 'Somewhere']);

        $result = app(BranchProvisioningService::class)->provision($branch);

        expect($result['created'])->toBeTrue()
            ->and($result['location']->type)->toBe('satellite')
            ->and($result['location']->branch_id)->toBe($branch->id)
            ->and($result['location']->name)->toBe('Test Branch Branch')
            ->and($result['location']->is_active)->toBeTrue();
    });

    it('does not create a second location for a branch that has one', function () {
        $branch = Branch::factory()->create();
        $provisioning = app(BranchProvisioningService::class);

        $first = $provisioning->provision($branch);
        $second = $provisioning->provision($branch);

        expect($second['created'])->toBeFalse()
            ->and($second['location']->id)->toBe($first['location']->id)
            ->and(Location::where('branch_id', $branch->id)->count())->toBe(1);
    });

    it('leaves a deactivated location alone rather than splitting the stock', function () {
        $branch = Branch::factory()->create();
        $dormant = Location::factory()->create([
            'branch_id' => $branch->id,
            'type' => 'satellite',
            'is_active' => false,
        ]);

        $result = app(BranchProvisioningService::class)->provision($branch);

        expect($result['created'])->toBeFalse()
            ->and($result['location']->id)->toBe($dormant->id);
    });

    it('hands out sequential codes without reusing one', function () {
        Location::factory()->create(['code' => 'SK-001', 'type' => 'satellite']);
        Location::factory()->create(['code' => 'SK-007', 'type' => 'satellite']);

        $branch = Branch::factory()->create();
        $result = app(BranchProvisioningService::class)->provision($branch);

        expect($result['location']->code)->toBe('SK-008');
    });

    it('provisions automatically when an admin creates a branch', function () {
        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id, 'status' => EmployeeStatus::Active]);
        $user->assignRole(RoleEnum::Admin->value);

        $this->actingAs($user->fresh())
            ->postJson('/v1/admin/branches', [
                'name' => 'Kasoa',
                'area' => 'Kasoa',
                'address' => 'Kasoa Main Road',
                'phone' => '+233541234567',
                'latitude' => 5.5340,
                'longitude' => -0.4160,
            ])
            ->assertCreated();

        $branch = Branch::where('name', 'Kasoa')->firstOrFail();

        expect(Location::where('branch_id', $branch->id)->exists())->toBeTrue();
    });

    it('lists the branches that drifted', function () {
        $wired = Branch::factory()->create();
        Location::factory()->create(['branch_id' => $wired->id, 'type' => 'satellite']);

        $adrift = Branch::factory()->create(['is_active' => true]);
        Branch::factory()->create(['is_active' => false]); // inactive — not our problem

        $missing = app(BranchProvisioningService::class)->unprovisionedBranches();

        expect($missing->pluck('id')->all())->toBe([$adrift->id]);
    });
});

describe('the backfill command', function () {
    it('provisions every active branch that has none', function () {
        Branch::factory()->count(2)->create(['is_active' => true]);

        $this->artisan('branch:provision-locations')->assertSuccessful();

        expect(app(BranchProvisioningService::class)->unprovisionedBranches())->toBeEmpty();
    });

    it('changes nothing on a dry run', function () {
        $branch = Branch::factory()->create(['is_active' => true]);

        $this->artisan('branch:provision-locations', ['--dry-run' => true])->assertSuccessful();

        expect(Location::where('branch_id', $branch->id)->exists())->toBeFalse();
    });

    it('is safe to run twice', function () {
        Branch::factory()->create(['is_active' => true]);

        $this->artisan('branch:provision-locations')->assertSuccessful();
        $this->artisan('branch:provision-locations')->assertSuccessful();

        expect(Location::where('type', 'satellite')->count())->toBe(1);
    });
});

describe('a sale at a branch with no location', function () {
    it('raises a critical alert instead of quietly eating the warehouse', function () {
        $warehouse = Location::factory()->create([
            'type' => 'warehouse',
            'branch_id' => null,
            'name' => 'Mother Kitchen',
            'is_active' => true,
        ]);
        $branch = Branch::factory()->create(['name' => 'Unwired Branch']);

        $order = \App\Models\Order::factory()->create(['branch_id' => $branch->id]);

        // resolveDeductionLocation is private; deduct() reaches it on the way in.
        app(\App\Domain\Inventory\Recipes\RecipeDeductionService::class)->deductForOrder($order);

        $alert = Alert::where('type', 'misrouted_deduction')
            ->where('reference_id', $branch->id)
            ->first();

        expect($alert)->not->toBeNull()
            ->and($alert->severity)->toBe('critical')
            ->and($alert->status)->toBe('open')
            ->and($alert->location_id)->toBe($warehouse->id)
            ->and($alert->message)->toContain('Unwired Branch')
            ->and($alert->message)->toContain('Mother Kitchen');
    });

    it('keeps one alert per branch rather than one per sale', function () {
        Location::factory()->create(['type' => 'warehouse', 'branch_id' => null, 'is_active' => true]);
        $branch = Branch::factory()->create();

        $service = app(\App\Domain\Inventory\Recipes\RecipeDeductionService::class);

        foreach (range(1, 3) as $_) {
            $service->deductForOrder(\App\Models\Order::factory()->create(['branch_id' => $branch->id]));
        }

        expect(Alert::where('type', 'misrouted_deduction')->count())->toBe(1);
    });

    it('raises nothing once the branch has its own location', function () {
        Location::factory()->create(['type' => 'warehouse', 'branch_id' => null, 'is_active' => true]);
        $branch = Branch::factory()->create();
        app(BranchProvisioningService::class)->provision($branch);

        app(\App\Domain\Inventory\Recipes\RecipeDeductionService::class)
            ->deductForOrder(\App\Models\Order::factory()->create(['branch_id' => $branch->id]));

        expect(Alert::where('type', 'misrouted_deduction')->exists())->toBeFalse();
    });
});
