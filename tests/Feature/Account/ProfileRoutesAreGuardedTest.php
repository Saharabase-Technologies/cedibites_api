<?php

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| The profile routes actually have a guard on them
|--------------------------------------------------------------------------
|
| routes/auth.php read:
|
|     Route::middleware('auth:sanctum')->middleware('customer.active')
|
| and RouteRegistrar::attribute() *assigns* the middleware attribute rather than
| merging it — the merge branch in that method exists only for
| withoutMiddleware. So the second call threw the first away and /auth/user,
| /auth/profile and /auth/logout ran with no authentication at all.
|
| Nothing refused the request, because EnsureCustomerActive returns early on a
| null user by design. It reached the controller instead, where $request->user()
| was null, and a customer trying to change their own name got "Attempt to read
| property id on null". Editing a name or an email had never once worked.
|
| The behaviour tests below would have caught it. The middleware assertion is
| here as well because it names the actual cause, and because a future chained
| ->middleware() would break these routes the same silent way.
|
*/

it('has the sanctum guard on every profile route', function (string $method, string $uri) {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true));

    expect($route)->not->toBeNull("route {$method} {$uri} is not registered");
    expect($route->gatherMiddleware())->toContain('auth:sanctum');
})->with([
    ['GET', 'v1/auth/user'],
    ['PATCH', 'v1/auth/profile'],
    ['POST', 'v1/auth/logout'],
]);

it('turns a guest away from every profile route', function () {
    test()->getJson('/v1/auth/user')->assertUnauthorized();
    test()->patchJson('/v1/auth/profile', ['name' => 'Nobody'])->assertUnauthorized();
    test()->postJson('/v1/auth/logout')->assertUnauthorized();
});

it('lets a signed-in customer read their own account', function () {
    $user = User::factory()->create(['name' => 'Akosua Mensah']);
    Customer::factory()->create(['user_id' => $user->id, 'is_guest' => false]);

    Sanctum::actingAs($user->fresh('customer'), ['customer']);

    $response = test()->getJson('/v1/auth/user')->assertOk();

    expect($response->json('data.name'))->toBe('Akosua Mensah');
});

it('changes a name', function () {
    $user = User::factory()->create(['name' => 'Old Name']);
    Customer::factory()->create(['user_id' => $user->id, 'is_guest' => false]);

    Sanctum::actingAs($user->fresh('customer'), ['customer']);

    test()->patchJson('/v1/auth/profile', ['name' => 'Somda'])->assertOk();

    expect($user->fresh()->name)->toBe('Somda');
});

it('changes an email, and takes it away again', function () {
    $user = User::factory()->create(['email' => null]);
    Customer::factory()->create(['user_id' => $user->id, 'is_guest' => false]);

    Sanctum::actingAs($user->fresh('customer'), ['customer']);

    test()->patchJson('/v1/auth/profile', ['email' => 'akosua@example.com'])->assertOk();
    expect($user->fresh()->email)->toBe('akosua@example.com');

    // The account page sends null to clear it, which the rules allow.
    test()->patchJson('/v1/auth/profile', ['email' => null])->assertOk();
    expect($user->fresh()->email)->toBeNull();
});

it('refuses an email another account already holds', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $user = User::factory()->create(['email' => null]);
    Customer::factory()->create(['user_id' => $user->id, 'is_guest' => false]);

    Sanctum::actingAs($user->fresh('customer'), ['customer']);

    test()->patchJson('/v1/auth/profile', ['email' => 'taken@example.com'])
        ->assertStatus(422);
});
