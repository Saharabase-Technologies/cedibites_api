<?php

use App\Enums\CustomerStatus;
use App\Enums\EmployeeStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\OTPService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
|--------------------------------------------------------------------------
| Staff / customer token separation
|--------------------------------------------------------------------------
|
| One human can hold both identities — the warehouse manager orders lunch from
| the same phone he runs stock with — so `users` carries one identity and
| `employees` / `customers` hang off it. That means a permission check alone
| asks the wrong question: it asks who the user is, when the question is which
| door they came through.
|
| These tests drive real minted bearer tokens rather than actingAs(), because
| actingAs() leaves no personal access token at all and so cannot exercise the
| gate.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
});

/**
 * A staff member who is also a customer — the dual identity this all turns on.
 *
 * @return array{user: User, plainPassword: string}
 */
function dualIdentityUser(string $role = 'tech_admin'): array
{
    $branch = Branch::factory()->create();

    $user = User::factory()->create([
        'name' => 'Richardson',
        'password' => 'staff-password',
    ]);

    $employee = Employee::factory()->create([
        'user_id' => $user->id,
        'status' => EmployeeStatus::Active,
    ]);
    $employee->branches()->attach($branch);

    $user->assignRole($role);

    // They have ordered before, so a customer record exists on the same identity.
    Customer::factory()->create([
        'user_id' => $user->id,
        'is_guest' => true,
        'status' => CustomerStatus::Active,
    ]);

    return ['user' => $user->fresh(), 'plainPassword' => 'staff-password'];
}

function bearer(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

it('refuses the staff surface to a token minted by the customer OTP login', function () {
    ['user' => $user] = dualIdentityUser();

    // The whole credential is an SMS to a phone the system already trusts.
    $service = app(OTPService::class);
    $otp = $service->generate();
    $service->store($user->phone, $otp);

    $verified = $this->postJson('/v1/auth/verify-otp', [
        'phone' => $user->phone,
        'otp' => $otp,
    ]);

    // Dual identity is deliberate: they may sign in as a customer.
    $verified->assertSuccessful();
    $customerToken = $verified->json('data.token');

    expect($customerToken)->not->toBeNull();

    // But that token opens nothing on the staff side, despite the underlying
    // user holding every permission tech_admin carries.
    $this->withHeaders(bearer($customerToken))
        ->getJson('/v1/admin/customers')
        ->assertForbidden()
        ->assertJsonPath('error', 'staff_token_required');

    $this->withHeaders(bearer($customerToken))
        ->getJson('/v1/inventory/items')
        ->assertForbidden();
});

it('opens the staff surface to a token minted by the staff password login', function () {
    ['user' => $user, 'plainPassword' => $password] = dualIdentityUser();

    $login = $this->postJson('/v1/employee/login', [
        'identifier' => $user->phone,
        'password' => $password,
    ]);

    $login->assertSuccessful();
    $staffToken = $login->json('data.token');

    expect($staffToken)->not->toBeNull();

    $this->withHeaders(bearer($staffToken))
        ->getJson('/v1/admin/customers')
        ->assertSuccessful();
});

it('refuses the staff surface to a legacy wildcard token', function () {
    ['user' => $user] = dualIdentityUser();

    // Every token minted before abilities existed carries '*'. Sanctum's own
    // tokenCan() would honour it; the gate deliberately does not, so the
    // escalation window closes on deploy rather than 24h later when the last
    // pre-existing token expires.
    $legacy = $user->createToken('auth-token')->plainTextToken;

    $this->withHeaders(bearer($legacy))
        ->getJson('/v1/admin/customers')
        ->assertForbidden();
});

it('does not rename the staff identity when their phone orders under another name', function () {
    ['user' => $user] = dualIdentityUser();

    $branch = Branch::factory()->create();

    // The same phone walks in and gives a different name at the counter.
    \App\Models\Order::factory()->create([
        'customer_id' => $user->customer->id,
        'branch_id' => $branch->id,
        'contact_name' => 'Philippa',
        'contact_phone' => $user->phone,
    ]);

    // The order carries the name spoken; the account keeps its own.
    expect($user->fresh()->name)->toBe('Richardson');
});

it('requires a verified OTP before an account can be claimed', function () {
    $user = User::factory()->create(['password' => null, 'name' => 'Akosua']);
    Customer::factory()->create(['user_id' => $user->id, 'is_guest' => true]);

    // Knowing a number that once ordered must not be enough to become them.
    $this->postJson('/v1/auth/quick-register', [
        'name' => 'Someone Else',
        'phone' => $user->phone,
    ])->assertUnprocessable()
        ->assertJsonPath('errors.phone.0', 'Please verify your phone number first');

    // With the number proved, the same call succeeds.
    $service = app(OTPService::class);
    $otp = $service->generate();
    $service->store($user->phone, $otp);
    $service->verify($user->phone, $otp);

    $this->postJson('/v1/auth/quick-register', [
        'name' => 'Akosua',
        'phone' => $user->phone,
    ])->assertSuccessful();
});

it('marks a guest as claimed once they verify their number', function () {
    $user = User::factory()->create(['password' => null]);
    $customer = Customer::factory()->create(['user_id' => $user->id, 'is_guest' => true]);

    $service = app(OTPService::class);
    $otp = $service->generate();
    $service->store($user->phone, $otp);

    $this->postJson('/v1/auth/verify-otp', [
        'phone' => $user->phone,
        'otp' => $otp,
    ])->assertSuccessful();

    // Leaving the flag set was silent but not harmless — suspension, the guest
    // analytics split and the admin list filter all key off it.
    expect($customer->fresh()->is_guest)->toBeFalse();
});
