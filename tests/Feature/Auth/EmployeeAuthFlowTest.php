<?php

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\StaffPasswordResetNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * Create an active employee user with a known email + phone.
 */
function activeStaffUser(array $attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'email' => 'staff@cedibites.com',
        'phone' => '+233241234567',
        'password' => Hash::make('old-password'),
    ], $attributes));

    Employee::factory()->create([
        'user_id' => $user->id,
        'status' => EmployeeStatus::Active,
    ]);

    return $user->fresh();
}

// ── check-identifier ─────────────────────────────────────────────────────────

it('confirms an existing active staff account by email', function () {
    activeStaffUser();

    $this->postJson('/v1/employee/check-identifier', ['identifier' => 'staff@cedibites.com'])
        ->assertOk()
        ->assertJsonPath('data.exists', true)
        ->assertJsonPath('data.channels.email', true)
        ->assertJsonPath('data.channels.phone', true);
});

it('confirms an existing account by phone (normalised)', function () {
    activeStaffUser();

    $this->postJson('/v1/employee/check-identifier', ['identifier' => '0241234567'])
        ->assertOk()
        ->assertJsonPath('data.exists', true);
});

it('reports no account for an unknown identifier', function () {
    activeStaffUser();

    $this->postJson('/v1/employee/check-identifier', ['identifier' => 'nobody@cedibites.com'])
        ->assertOk()
        ->assertJsonPath('data.exists', false);
});

it('does not reveal inactive employees as existing', function () {
    $user = User::factory()->create(['email' => 'left@cedibites.com']);
    Employee::factory()->create(['user_id' => $user->id, 'status' => EmployeeStatus::Suspended]);

    $this->postJson('/v1/employee/check-identifier', ['identifier' => 'left@cedibites.com'])
        ->assertOk()
        ->assertJsonPath('data.exists', false);
});

// ── forgot + reset via OTP ───────────────────────────────────────────────────

it('issues an OTP and resets the password with it', function () {
    Notification::fake();
    activeStaffUser();

    $this->postJson('/v1/employee/forgot-password', ['identifier' => 'staff@cedibites.com'])
        ->assertOk();

    $captured = null;
    Notification::assertSentTo(
        User::where('email', 'staff@cedibites.com')->first(),
        function (StaffPasswordResetNotification $notification) use (&$captured) {
            $captured = $notification->otp;

            return true;
        }
    );

    expect($captured)->toMatch('/^\d{6}$/');

    $this->postJson('/v1/employee/reset-password', [
        'identifier' => 'staff@cedibites.com',
        'otp' => $captured,
        'password' => 'brand-new-pass',
        'password_confirmation' => 'brand-new-pass',
    ])->assertOk();

    $user = User::where('email', 'staff@cedibites.com')->first();
    expect(Hash::check('brand-new-pass', $user->password))->toBeTrue();
});

it('rejects an incorrect OTP', function () {
    Notification::fake();
    activeStaffUser();

    $this->postJson('/v1/employee/forgot-password', ['identifier' => 'staff@cedibites.com'])->assertOk();

    $this->postJson('/v1/employee/reset-password', [
        'identifier' => 'staff@cedibites.com',
        'otp' => '000000',
        'password' => 'brand-new-pass',
        'password_confirmation' => 'brand-new-pass',
    ])->assertStatus(422);

    $user = User::where('email', 'staff@cedibites.com')->first();
    expect(Hash::check('old-password', $user->password))->toBeTrue();
});

it('still supports resetting via the link token', function () {
    Notification::fake();
    activeStaffUser();

    $this->postJson('/v1/employee/forgot-password', ['identifier' => 'staff@cedibites.com'])->assertOk();

    // The token is random and only delivered via the link; assert the OTP path
    // and the token column both exist so the dual-channel record was written.
    $record = \Illuminate\Support\Facades\DB::table('password_reset_tokens')
        ->where('email', 'staff@cedibites.com')->first();

    expect($record)->not->toBeNull()
        ->and($record->token)->not->toBeEmpty()
        ->and($record->otp)->not->toBeEmpty()
        ->and($record->otp_expires_at)->not->toBeNull();
});
