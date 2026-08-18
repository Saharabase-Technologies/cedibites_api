<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * @param  array<string, mixed>  $itemAttributes
 */
function menuItemWithUnitPrice(\App\Models\Branch $branch, float $unitPrice, array $itemAttributes = []): \App\Models\MenuItem
{
    $item = \App\Models\MenuItem::factory()->create(array_merge($itemAttributes, [
        'branch_id' => $branch->id,
    ]));
    $item->options()->first()?->update(['price' => $unitPrice]);

    return $item->fresh(['options']);
}

function firstOptionUnitPrice(\App\Models\MenuItem $item): float
{
    return (float) $item->options()->orderBy('display_order')->first()->price;
}

/*
|--------------------------------------------------------------------------
| Staff messaging
|--------------------------------------------------------------------------
|
| Here rather than in one of the test files because several files need them.
| Pest shares global function scope across the suite, so a helper defined in
| StaffAudienceTest.php is visible to StaffInboxTest.php ONLY when both files
| are loaded — running one file alone then dies on an undefined function. The
| `msg` prefix keeps them from colliding with anybody else's helper, which is
| the failure that fatals the whole suite while passing in isolation.
|
*/

/**
 * A user with an active employee record, one role, and optional branches.
 *
 * @param  list<\App\Models\Branch>  $branches
 */
function msgStaff(
    string $role,
    array $branches = [],
    \App\Enums\EmployeeStatus $status = \App\Enums\EmployeeStatus::Active,
): \App\Models\User {
    $user = \App\Models\User::factory()->create();
    $employee = \App\Models\Employee::factory()->create([
        'user_id' => $user->id,
        'status' => $status,
    ]);

    foreach ($branches as $branch) {
        $employee->branches()->attach($branch);
    }

    $user->assignRole($role);

    return $user->fresh();
}

/** Company-wide staff — no branch attachment, which is what the role means. */
function msgSender(string $role): \App\Models\User
{
    return msgStaff($role);
}
