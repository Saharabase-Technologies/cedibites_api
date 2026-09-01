<?php

use App\Enums\EmployeeStatus;
use App\Events\CustomerSessionEvent;
use App\Events\StaffSessionEvent;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

const PASSCODE = '123456';

function techAdmin(): User
{
    $this_ = test();
    $this_->seed(PermissionSeeder::class);
    $this_->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'platform_passcode' => Hash::make(PASSCODE),
    ]);

    Employee::factory()->create([
        'user_id' => $user->id,
        'status' => EmployeeStatus::Active,
    ]);

    $user->assignRole('tech_admin');

    return $user->fresh();
}

/** A signed-in session for somebody else, with a chosen age. */
function sessionFor(User $user, string $tokenName = 'employee-auth-token', int $idleSeconds = 10): int
{
    $token = $user->createToken($tokenName, ['staff']);

    DB::table('personal_access_tokens')
        ->where('id', $token->accessToken->id)
        ->update([
            'last_used_at' => now()->subSeconds($idleSeconds),
            'created_at' => now()->subMinutes(30),
        ]);

    return $token->accessToken->id;
}

/*
|--------------------------------------------------------------------------
| Who is signed in right now
|--------------------------------------------------------------------------
*/

it('separates people at a screen from terminals left signed in', function () {
    $admin = techAdmin();

    $atTheTill = User::factory()->create(['name' => 'Ama']);
    $walkedAway = User::factory()->create(['name' => 'Kofi']);
    $yesterdaysTerminal = User::factory()->create(['name' => 'Yaw']);

    sessionFor($atTheTill, idleSeconds: 30);
    sessionFor($walkedAway, idleSeconds: 600);
    sessionFor($yesterdaysTerminal, idleSeconds: 7200);

    $response = $this->actingAs($admin, 'sanctum')->getJson('/v1/platform/sessions');

    $response->assertOk()
        ->assertJsonPath('meta.online', 1)
        ->assertJsonPath('meta.idle', 1)
        ->assertJsonPath('meta.away', 1);

    $rows = collect($response->json('data'))->keyBy('name');

    expect($rows['Ama']['status'])->toBe('online')
        ->and($rows['Kofi']['status'])->toBe('idle')
        ->and($rows['Yaw']['status'])->toBe('away');
});

it('leaves out tokens that have already expired', function () {
    // Sanctum expires a token `expiration` minutes after it was minted and
    // nothing prunes the row, so an expired session that made its last request
    // inside the window used to sit in the list looking live.
    config()->set('sanctum.expiration', 60);

    $admin = techAdmin();
    $stale = User::factory()->create(['name' => 'Expired']);

    $tokenId = sessionFor($stale, idleSeconds: 30);
    DB::table('personal_access_tokens')
        ->where('id', $tokenId)
        ->update(['created_at' => now()->subMinutes(120)]);

    $response = $this->actingAs($admin, 'sanctum')->getJson('/v1/platform/sessions');

    expect(collect($response->json('data'))->pluck('name'))->not->toContain('Expired');
});

it('marks the reader\'s own session so they do not end it by accident', function () {
    $admin = techAdmin();
    $adminToken = sessionFor($admin, idleSeconds: 5);

    $response = $this->withToken(
        // Acting through the real token so `currentAccessToken()` is populated.
        $admin->createToken('probe', ['staff'])->plainTextToken
    )->getJson('/v1/platform/sessions');

    $response->assertOk();

    $mine = collect($response->json('data'))->firstWhere('token_id', $adminToken);

    expect($mine['is_current'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Ending one
|--------------------------------------------------------------------------
*/

it('signs one device out and leaves the person\'s other devices alone', function () {
    Event::fake([StaffSessionEvent::class, CustomerSessionEvent::class]);

    $admin = techAdmin();
    $cashier = User::factory()->create(['name' => 'Ama']);

    $till = sessionFor($cashier, 'employee-auth-token');
    $phone = sessionFor($cashier, 'auth-token');

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/v1/platform/sessions/{$till}", ['passcode' => PASSCODE])
        ->assertOk();

    expect(DB::table('personal_access_tokens')->where('id', $till)->exists())->toBeFalse()
        ->and(DB::table('personal_access_tokens')->where('id', $phone)->exists())->toBeTrue();

    // The per-user channel reaches every device this person holds, so a
    // broadcast here would have cleared the phone as well as the till.
    Event::assertNotDispatched(StaffSessionEvent::class);
    Event::assertNotDispatched(CustomerSessionEvent::class);
});

it('refuses without the passcode', function () {
    $admin = techAdmin();
    $cashier = User::factory()->create();
    $token = sessionFor($cashier);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/v1/platform/sessions/{$token}", ['passcode' => '000000'])
        ->assertStatus(403);

    expect(DB::table('personal_access_tokens')->where('id', $token)->exists())->toBeTrue();
});

it('will not let an admin cut off the session they are using', function () {
    $admin = techAdmin();
    $token = $admin->createToken('employee-auth-token', ['staff']);

    $this->withToken($token->plainTextToken)
        ->deleteJson("/v1/platform/sessions/{$token->accessToken->id}", ['passcode' => PASSCODE])
        ->assertStatus(422);

    expect(DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Ending all of somebody's
|--------------------------------------------------------------------------
*/

it('signs a person out everywhere and tells every one of their screens', function () {
    Event::fake([StaffSessionEvent::class, CustomerSessionEvent::class]);

    $admin = techAdmin();
    $cashier = User::factory()->create(['name' => 'Ama']);

    sessionFor($cashier, 'employee-auth-token');
    sessionFor($cashier, 'auth-token');

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/v1/platform/sessions/user/{$cashier->id}", ['passcode' => PASSCODE])
        ->assertOk();

    expect($cashier->tokens()->count())->toBe(0);

    // One person, both kinds of token: each client listens on its own event
    // name, so one broadcast would leave the other screen holding a dead
    // session.
    Event::assertDispatched(StaffSessionEvent::class);
    Event::assertDispatched(CustomerSessionEvent::class);
});

it('only announces the kinds of session the person actually held', function () {
    Event::fake([StaffSessionEvent::class, CustomerSessionEvent::class]);

    $admin = techAdmin();
    $customer = User::factory()->create();
    sessionFor($customer, 'auth-token');

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/v1/platform/sessions/user/{$customer->id}", ['passcode' => PASSCODE])
        ->assertOk();

    Event::assertDispatched(CustomerSessionEvent::class);
    Event::assertNotDispatched(StaffSessionEvent::class);
});

it('says so plainly when there is nothing to end', function () {
    $admin = techAdmin();
    $nobody = User::factory()->create();

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/v1/platform/sessions/user/{$nobody->id}", ['passcode' => PASSCODE])
        ->assertStatus(404);
});
