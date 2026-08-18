<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use App\Services\StaffMessaging\StaffAudienceResolver;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
|--------------------------------------------------------------------------
| Who a message actually reaches
|--------------------------------------------------------------------------
|
| The company-wide trap is the reason this file is longer than it looks. Head
| office, the warehouse, purchasing and the call centre hold NO branch
| assignment, so a plain pivot filter reads them as belonging to no branch and
| excludes them from every one — when the truth is that they serve all of them.
| The same misreading once hid every order from the call centre.
|
*/

// msgStaff() lives in tests/Pest.php — several files need it, and a helper
// defined here is only visible to them when this file also happens to be loaded.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->resolver = app(StaffAudienceResolver::class);
});

it('reaches riders at one branch without touching another branch', function () {
    $ashaiman = Branch::factory()->create();
    $other = Branch::factory()->create();

    $wanted = msgStaff(RoleEnum::Rider->value, [$ashaiman]);
    $notWanted = msgStaff(RoleEnum::Rider->value, [$other]);

    $reached = $this->resolver->resolve([
        'roles' => [RoleEnum::Rider->value],
        'branch_ids' => [$ashaiman->id],
    ]);

    expect($reached->pluck('id'))->toContain($wanted->id)
        ->and($reached->pluck('id'))->not->toContain($notWanted->id);
});

it('includes company-wide staff when a branch is chosen', function () {
    $branch = Branch::factory()->create();

    $cashier = msgStaff(RoleEnum::SalesStaff->value, [$branch]);
    // No branch attachment — that is what the role means, not an omission.
    $callCentre = msgStaff(RoleEnum::CallCenter->value);

    $reached = $this->resolver->resolve(['branch_ids' => [$branch->id]]);

    expect($reached->pluck('id'))->toContain($cashier->id)
        ->and($reached->pluck('id'))->toContain($callCentre->id);
});

it('leaves company-wide staff out when the caller says to', function () {
    $branch = Branch::factory()->create();

    $cashier = msgStaff(RoleEnum::SalesStaff->value, [$branch]);
    $callCentre = msgStaff(RoleEnum::CallCenter->value);

    $reached = $this->resolver->resolve([
        'branch_ids' => [$branch->id],
        'include_company_wide' => false,
    ]);

    expect($reached->pluck('id'))->toContain($cashier->id)
        ->and($reached->pluck('id'))->not->toContain($callCentre->id);
});

it('never reaches a suspended employee', function () {
    $branch = Branch::factory()->create();

    $active = msgStaff(RoleEnum::SalesStaff->value, [$branch]);
    $suspended = msgStaff(RoleEnum::SalesStaff->value, [$branch], EmployeeStatus::Suspended);

    $reached = $this->resolver->resolve(['roles' => [RoleEnum::SalesStaff->value]]);

    expect($reached->pluck('id'))->toContain($active->id)
        ->and($reached->pluck('id'))->not->toContain($suspended->id);
});

it('lets a named person through the role and branch filters', function () {
    $branch = Branch::factory()->create();

    $rider = msgStaff(RoleEnum::Rider->value, [$branch]);
    // A manager at another branch: matches neither the role nor the branch, and
    // must still arrive because somebody typed their name.
    $manager = msgStaff(RoleEnum::Manager->value, [Branch::factory()->create()]);

    $reached = $this->resolver->resolve([
        'roles' => [RoleEnum::Rider->value],
        'branch_ids' => [$branch->id],
        'user_ids' => [$manager->id],
    ]);

    expect($reached->pluck('id'))->toContain($rider->id)
        ->and($reached->pluck('id'))->toContain($manager->id);
});

it('reaches nobody when nothing at all was chosen', function () {
    msgStaff(RoleEnum::Rider->value, [Branch::factory()->create()]);

    expect($this->resolver->resolve([])->count())->toBe(0);
});

it('counts each person once when they match several ways', function () {
    $branch = Branch::factory()->create();
    $rider = msgStaff(RoleEnum::Rider->value, [$branch]);

    $reached = $this->resolver->resolve([
        'roles' => [RoleEnum::Rider->value],
        'branch_ids' => [$branch->id],
        'user_ids' => [$rider->id],
    ]);

    expect($reached->where('id', $rider->id)->count())->toBe(1);
});
