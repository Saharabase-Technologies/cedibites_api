<?php

use App\Enums\EmployeeStatus;
use App\Events\CustomerSessionEvent;
use App\Events\StaffSessionEvent;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use App\Services\SessionDeviceService;
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

/*
|--------------------------------------------------------------------------
| What machine, and which branch
|--------------------------------------------------------------------------
*/

it('tells a phone from a tablet from a till', function () {
    $service = app(SessionDeviceService::class);

    expect($service->classify('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1'))->toBe('mobile')
        ->and($service->classify('Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36'))->toBe('mobile')
        ->and($service->classify('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1'))->toBe('tablet')
        // Every Android tablet also says "Android"; only the absence of
        // "Mobile" separates it from a phone.
        ->and($service->classify('Mozilla/5.0 (Linux; Android 13; SM-X200) AppleWebKit/537.36 Chrome/120 Safari/537.36'))->toBe('tablet')
        ->and($service->classify('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36'))->toBe('desktop')
        ->and($service->classify('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1'))->toBe('desktop')
        ->and($service->classify(null))->toBe('unknown')
        ->and($service->classify(''))->toBe('unknown');
});

it('names the browser without being fooled by the ones that impersonate Chrome', function () {
    $service = app(SessionDeviceService::class);

    // Edge and Opera both carry "chrome" in their strings, and Chrome carries
    // "safari" — order in the match is what keeps these apart.
    expect($service->browser('Mozilla/5.0 Chrome/120 Safari/537.36 Edg/120'))->toBe('Edge')
        ->and($service->browser('Mozilla/5.0 Chrome/120 Safari/537.36 OPR/106'))->toBe('Opera')
        ->and($service->browser('Mozilla/5.0 Chrome/120 Safari/537.36'))->toBe('Chrome')
        ->and($service->browser('Mozilla/5.0 (Macintosh) Version/17.0 Safari/605.1'))->toBe('Safari')
        ->and($service->browser(null))->toBeNull();
});

it('records the device a session was signed in on', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'password' => Hash::make('secret-pass-1'),
        'must_reset_password' => false,
    ]);
    $employee = Employee::factory()->create([
        'user_id' => $user->id,
        'status' => EmployeeStatus::Active,
    ]);
    $user->assignRole('sales_staff');

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1',
    ])->postJson('/v1/employee/login', [
        'identifier' => $user->phone,
        'password' => 'secret-pass-1',
    ])->assertOk();

    $token = DB::table('personal_access_tokens')
        ->where('tokenable_id', $user->id)
        ->first();

    expect($token)->not->toBeNull()
        ->and($token->user_agent)->toContain('iPad');
});

it('names the branch for branch staff and leaves it off for company-wide roles', function () {
    $admin = techAdmin();

    $branch = Branch::factory()->create(['name' => 'Ashaiman']);

    $cashier = User::factory()->create(['name' => 'Ama']);
    $cashierEmployee = Employee::factory()->create([
        'user_id' => $cashier->id,
        'status' => EmployeeStatus::Active,
    ]);
    $cashierEmployee->branches()->attach($branch->id);
    $cashier->assignRole('sales_staff');

    $boss = User::factory()->create(['name' => 'Kojo']);
    Employee::factory()->create(['user_id' => $boss->id, 'status' => EmployeeStatus::Active]);
    $boss->assignRole('admin');

    sessionFor($cashier);
    sessionFor($boss);

    $rows = collect(
        $this->actingAs($admin, 'sanctum')->getJson('/v1/platform/sessions')->json('data')
    )->keyBy('name');

    expect($rows['Ama']['branches'])->toBe(['Ashaiman'])
        // Null, not an empty list. An admin belongs to the company, and
        // printing "no branch" against their name reads as missing data rather
        // than the answer.
        ->and($rows['Kojo']['branches'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Signing out a whole category
|--------------------------------------------------------------------------
*/

it('signs out a selection of devices in one go', function () {
    $admin = techAdmin();

    $one = User::factory()->create();
    $two = User::factory()->create();

    $a = sessionFor($one, idleSeconds: 7200);
    $b = sessionFor($two, idleSeconds: 7200);
    $keep = sessionFor($two, idleSeconds: 10);

    $this->actingAs($admin, 'sanctum')->postJson('/v1/platform/sessions/revoke', [
        'token_ids' => [$a, $b],
        'passcode' => PASSCODE,
    ])->assertOk();

    expect(DB::table('personal_access_tokens')->whereIn('id', [$a, $b])->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->where('id', $keep)->exists())->toBeTrue();
});

it('skips the admin\'s own session rather than refusing the whole batch', function () {
    // One live session in a selection of forty is not a reason to make somebody
    // redo the selection.
    $admin = techAdmin();
    $other = User::factory()->create();
    $theirs = sessionFor($other);

    $mine = $admin->createToken('employee-auth-token', ['staff']);

    $response = $this->withToken($mine->plainTextToken)->postJson('/v1/platform/sessions/revoke', [
        'token_ids' => [$mine->accessToken->id, $theirs],
        'passcode' => PASSCODE,
    ]);

    $response->assertOk();

    expect(DB::table('personal_access_tokens')->where('id', $mine->accessToken->id)->exists())->toBeTrue()
        ->and(DB::table('personal_access_tokens')->where('id', $theirs)->exists())->toBeFalse()
        ->and($response->json('message'))->toContain('own session');
});

it('needs the passcode for a bulk sign-out', function () {
    $admin = techAdmin();
    $other = User::factory()->create();
    $token = sessionFor($other);

    $this->actingAs($admin, 'sanctum')->postJson('/v1/platform/sessions/revoke', [
        'token_ids' => [$token],
        'passcode' => '000000',
    ])->assertStatus(403);

    expect(DB::table('personal_access_tokens')->where('id', $token)->exists())->toBeTrue();
});

it('tells the screens to clear only once nothing of theirs is left', function () {
    Event::fake([StaffSessionEvent::class, CustomerSessionEvent::class]);

    $admin = techAdmin();
    $person = User::factory()->create();

    $till = sessionFor($person, 'employee-auth-token');
    $phone = sessionFor($person, 'auth-token');

    // First device: a session survives, so a broadcast would clear the screen
    // of a device that is still legitimately signed in.
    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/v1/platform/sessions/{$till}", ['passcode' => PASSCODE])
        ->assertOk();

    Event::assertNotDispatched(StaffSessionEvent::class);
    Event::assertNotDispatched(CustomerSessionEvent::class);

    // Last one: nothing is meant to survive, so telling them is free.
    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/v1/platform/sessions/{$phone}", ['passcode' => PASSCODE])
        ->assertOk();

    Event::assertDispatched(CustomerSessionEvent::class);
});
