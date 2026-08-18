<?php

use App\Channels\SmsChannel;
use App\Enums\SmsFailureReason;
use App\Models\SmsDeliveryAttempt;
use App\Models\User;
use App\Notifications\SmsHealthAlertNotification;
use App\Services\HubtelSmsService;
use App\Services\SmsHealthService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Cache::flush();
    config()->set('services.hubtel.client_id', 'test-id');
    config()->set('services.hubtel.client_secret', 'test-secret');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function attempt(array $attributes = []): SmsDeliveryAttempt
{
    return SmsDeliveryAttempt::create(array_merge([
        'notification' => 'StaffPasswordResetNotification',
        'recipient' => '233241234567',
        'succeeded' => false,
        'failure_reason' => SmsFailureReason::NoCredit->value,
        'error_message' => 'SMS API Error: Payment required on account',
    ], $attributes));
}

/*
|--------------------------------------------------------------------------
| Classification
|--------------------------------------------------------------------------
*/

it('classifies the Hubtel out-of-credit rejection as no_credit', function () {
    // The exact string production returned for three weeks.
    expect(SmsFailureReason::classify('SMS API Error: Payment required on account'))
        ->toBe(SmsFailureReason::NoCredit);
});

it('classifies the other provider failures it must tell apart', function () {
    expect(SmsFailureReason::classify('Hubtel SMS is not properly configured'))->toBe(SmsFailureReason::ConfigMissing)
        ->and(SmsFailureReason::classify('401 Unauthorized'))->toBe(SmsFailureReason::AuthFailed)
        ->and(SmsFailureReason::classify('Invalid phone number format'))->toBe(SmsFailureReason::InvalidRecipient)
        ->and(SmsFailureReason::classify('Failed to connect to Hubtel SMS API'))->toBe(SmsFailureReason::Connection)
        ->and(SmsFailureReason::classify('something we have never seen'))->toBe(SmsFailureReason::Unknown)
        ->and(SmsFailureReason::classify(null))->toBe(SmsFailureReason::Unknown);
});

it('treats only the causes that need a human as systemic', function () {
    expect(SmsFailureReason::NoCredit->isSystemic())->toBeTrue()
        ->and(SmsFailureReason::AuthFailed->isSystemic())->toBeTrue()
        ->and(SmsFailureReason::ConfigMissing->isSystemic())->toBeTrue()
        ->and(SmsFailureReason::InvalidRecipient->isSystemic())->toBeFalse()
        ->and(SmsFailureReason::Connection->isSystemic())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Recording
|--------------------------------------------------------------------------
*/

it('records a failed send with the provider reason', function () {
    Http::fake(['sms.hubtel.com/*' => Http::response([
        'messageId' => null,
        'statusDescription' => 'Payment required on account',
    ], 200)]);

    expect(fn () => app(HubtelSmsService::class)->sendSingle('233241234567', 'hi', 'OrderReadyNotification'))
        ->toThrow(Exception::class);

    $row = SmsDeliveryAttempt::sole();

    expect($row->succeeded)->toBeFalse()
        ->and($row->failure_reason)->toBe(SmsFailureReason::NoCredit)
        ->and($row->notification)->toBe('OrderReadyNotification');
});

it('records a successful send', function () {
    Http::fake(['sms.hubtel.com/*' => Http::response([
        'messageId' => 'abc-123',
        'status' => 0,
    ], 200)]);

    app(HubtelSmsService::class)->sendSingle('233241234567', 'hi', 'OrderReadyNotification');

    $row = SmsDeliveryAttempt::sole();

    expect($row->succeeded)->toBeTrue()
        ->and($row->message_id)->toBe('abc-123')
        ->and($row->failure_reason)->toBeNull();
});

it('records an invalid recipient without calling the provider', function () {
    Http::fake();

    expect(fn () => app(HubtelSmsService::class)->sendSingle('12345', 'hi'))
        ->toThrow(InvalidArgumentException::class);

    expect(SmsDeliveryAttempt::sole()->failure_reason)->toBe(SmsFailureReason::InvalidRecipient);
    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Batch sending
|--------------------------------------------------------------------------
|
| Hubtel answers 2xx on the wire and reports business-level rejections in the
| body. A batch that trusts the HTTP status records one success per recipient
| for a send that reached nobody — the failure mode that makes a campaign
| dashboard lie.
*/

it('records a batch rejected in the body as failures, not successes', function () {
    // Status >= 100 is a rejection even though the wire says 200.
    Http::fake(['sms.hubtel.com/*' => Http::response([
        'messageIds' => ['a-1', 'a-2'],
        'status' => 100,
        'statusDescription' => 'Payment required on account',
    ], 200)]);

    expect(fn () => app(HubtelSmsService::class)->sendBatch(
        ['233241234567', '233241234568'],
        'hi',
        'PromoCampaign',
    ))->toThrow(Exception::class);

    expect(SmsDeliveryAttempt::where('succeeded', true)->count())->toBe(0)
        ->and(SmsDeliveryAttempt::where('succeeded', false)->count())->toBe(2)
        ->and(SmsDeliveryAttempt::first()->failure_reason)->toBe(SmsFailureReason::NoCredit);
});

it('treats a batch answered with a singular messageId as a failure', function () {
    // The exact shape the live endpoint returns when it rejects a batch: no
    // messageIds array at all, just a lone id and a failure status.
    Http::fake(['sms.hubtel.com/*' => Http::response([
        'rate' => 0,
        'messageId' => '4f33a4d3-492f-4353-9e74-10fd5bb8adfe',
        'status' => 100,
        'statusDescription' => null,
    ], 200)]);

    expect(fn () => app(HubtelSmsService::class)->sendBatch(['233241234567'], 'hi'))
        ->toThrow(Exception::class);

    expect(SmsDeliveryAttempt::sole()->succeeded)->toBeFalse();
});

/*
 * The shape the live endpoint ACTUALLY returns, captured from the beta account
 * on 2026-08-07: HTTP 201, a batchId, and a per-recipient `data` array. Not the
 * `messageIds` list the retired documentation described.
 *
 * Before this was handled, an accepted batch fell through to "Missing messageId
 * or messageIds" and every recipient was recorded as a failure — a campaign that
 * reached all four test phones reporting 0 delivered, 4 failed. The exact mirror
 * of the false-pass bug above, and the worse of the two to debug: the messages
 * arrive, so the only thing wrong is the record.
 */
it('accepts the batchId + data shape the live endpoint really returns', function () {
    Http::fake(['sms.hubtel.com/*' => Http::response([
        'batchId' => '2d417523-ba9c-4c88-aa7b-56704886e3d9',
        'status' => 0,
        'data' => [
            ['recipient' => '233241234567', 'content' => 'hi', 'messageId' => '9f6a82a9-1'],
            ['recipient' => '233241234568', 'content' => 'hi', 'messageId' => '9f6a82a9-2'],
        ],
    ], 201)]);

    $result = app(HubtelSmsService::class)->sendBatch(['233241234567', '233241234568'], 'hi');

    expect($result['messageIds'])->toBe(['9f6a82a9-1', '9f6a82a9-2'])
        ->and($result['batchId'])->toBe('2d417523-ba9c-4c88-aa7b-56704886e3d9')
        ->and(SmsDeliveryAttempt::where('succeeded', true)->count())->toBe(2)
        ->and(SmsDeliveryAttempt::where('succeeded', false)->count())->toBe(0);
});

/*
 * A batchId with no data is a rejection, not an acceptance. The new branch must
 * not swallow one — it requires `data` to be an array, so this falls through to
 * the statusDescription throw and is recorded as a failure per recipient.
 */
it('still treats a batchId carrying no data as a rejection', function () {
    Http::fake(['sms.hubtel.com/*' => Http::response([
        'batchId' => '2d417523-ba9c-4c88-aa7b-56704886e3d9',
        'status' => 4109,
        'statusDescription' => 'Payment required on account',
    ], 201)]);

    expect(fn () => app(HubtelSmsService::class)->sendBatch(['233241234567'], 'hi'))
        ->toThrow(Exception::class);

    expect(SmsDeliveryAttempt::sole()->succeeded)->toBeFalse()
        ->and(SmsDeliveryAttempt::sole()->failure_reason)->toBe(SmsFailureReason::NoCredit);
});

/* An empty data array reaches sendBatch's own guard and is a rejection too. */
it('treats a batch with an empty data array as a rejection', function () {
    Http::fake(['sms.hubtel.com/*' => Http::response([
        'batchId' => '2d417523-ba9c-4c88-aa7b-56704886e3d9',
        'status' => 0,
        'data' => [],
    ], 201)]);

    expect(fn () => app(HubtelSmsService::class)->sendBatch(['233241234567'], 'hi'))
        ->toThrow(Exception::class);

    expect(SmsDeliveryAttempt::sole()->succeeded)->toBeFalse();
});

it('records one success per recipient when the batch is accepted', function () {
    Http::fake(['sms.hubtel.com/*' => Http::response([
        'messageIds' => ['a-1', 'a-2', 'a-3'],
        'status' => 0,
    ], 200)]);

    app(HubtelSmsService::class)->sendBatch(
        ['233241234567', '233241234568', '233241234569'],
        'hi',
    );

    expect(SmsDeliveryAttempt::where('succeeded', true)->count())->toBe(3)
        ->and(SmsDeliveryAttempt::where('succeeded', false)->count())->toBe(0);
});

it('refuses an empty batch without spending a request', function () {
    Http::fake();

    expect(fn () => app(HubtelSmsService::class)->sendBatch([], 'hi'))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('marks campaign sends so they can be told apart from order notifications', function () {
    Http::fake(['sms.hubtel.com/*' => Http::response([
        'messageIds' => ['a-1'],
        'status' => 0,
    ], 200)]);

    app(HubtelSmsService::class)->sendBatch(['233241234567'], 'hi', 'FridayPromo', isCampaign: true);

    expect(SmsDeliveryAttempt::sole()->is_campaign)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Verdict
|--------------------------------------------------------------------------
*/

it('reports unknown rather than healthy when nothing has been sent', function () {
    // Silence is not proof of health, and must not be shown as green.
    expect(app(SmsHealthService::class)->check()['status'])->toBe('unknown');
});

it('goes critical on a single systemic failure', function () {
    // No credit fails every subsequent message; waiting for a rate to build is
    // waiting for customers to miss messages.
    attempt();

    $health = app(SmsHealthService::class)->check();

    expect($health['status'])->toBe('critical')
        ->and($health['reason'])->toBe('no_credit')
        ->and($health['systemic'])->toBeTrue()
        ->and($health['remedy'])->toContain('Top up');
});

it('does not go critical over a couple of bad phone numbers', function () {
    // One malformed number among healthy traffic is a data problem, not an
    // outage — the next message goes out fine.
    attempt(['failure_reason' => SmsFailureReason::InvalidRecipient->value, 'error_message' => 'Invalid phone number format']);

    foreach (range(1, 19) as $i) {
        SmsDeliveryAttempt::create(['recipient' => "23324123456{$i}", 'succeeded' => true]);
    }

    expect(app(SmsHealthService::class)->check()['status'])->toBe('healthy');
});

it('does not let a failed campaign trip the alert for order notifications', function () {
    // A rejected blast writes thousands of failures in one moment. That says
    // nothing about whether an order-ready SMS can still get through, and the
    // page must not claim otherwise.
    foreach (range(1, 200) as $i) {
        SmsDeliveryAttempt::create([
            'notification' => 'FridayPromo',
            'is_campaign' => true,
            'recipient' => "2332412345{$i}",
            'succeeded' => false,
            'failure_reason' => SmsFailureReason::NoCredit->value,
            'error_message' => 'SMS API Error: Payment required on account',
        ]);
    }

    foreach (range(1, 20) as $i) {
        SmsDeliveryAttempt::create(['recipient' => "23324123456{$i}", 'succeeded' => true]);
    }

    $health = app(SmsHealthService::class)->check();

    expect($health['status'])->toBe('healthy')
        ->and($health['failed'])->toBe(0)
        ->and($health['sent'])->toBe(20);
});

it('does not let campaign volume mask a real transactional outage', function () {
    // The exclusion has to cut both ways: a large successful blast must not
    // dilute the failure rate of the pipe that actually matters.
    foreach (range(1, 500) as $i) {
        SmsDeliveryAttempt::create([
            'is_campaign' => true,
            'recipient' => "2332412345{$i}",
            'succeeded' => true,
        ]);
    }

    attempt();

    expect(app(SmsHealthService::class)->check()['status'])->toBe('critical');
});

it('diagnoses from the current streak, not from errors before the last success', function () {
    // An old, resolved outage must not be the diagnosis for what is wrong now.
    attempt(['failure_reason' => SmsFailureReason::NoCredit->value, 'created_at' => now()->subHours(6)]);
    SmsDeliveryAttempt::create(['recipient' => 'x', 'succeeded' => true, 'created_at' => now()->subHours(4)]);
    attempt([
        'failure_reason' => SmsFailureReason::Connection->value,
        'error_message' => 'Failed to connect to Hubtel SMS API',
        'created_at' => now()->subMinutes(5),
    ]);

    $health = app(SmsHealthService::class)->check();

    expect($health['reason'])->toBe('connection')
        ->and($health['consecutive_failures'])->toBe(1);
});

it('counts which notifications are being lost', function () {
    attempt(['notification' => 'OrderReadyNotification']);
    attempt(['notification' => 'OrderReadyNotification']);
    attempt(['notification' => 'StaffPasswordResetNotification']);

    $affected = app(SmsHealthService::class)->check()['affected'];

    expect($affected[0]['notification'])->toBe('OrderReadyNotification')
        ->and($affected[0]['failures'])->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Alerting
|--------------------------------------------------------------------------
*/

it('alerts a user who can view system health', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    Notification::fake();

    $admin = User::factory()->create(['email' => 'tech@cedibites.com']);
    $admin->assignRole('tech_admin');

    attempt();

    $this->artisan('sms:health-check')->assertSuccessful();

    Notification::assertSentTo($admin, SmsHealthAlertNotification::class);
});

it('never sends the alert over SMS', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    Notification::fake();

    $admin = User::factory()->create(['email' => 'tech@cedibites.com']);
    $admin->assignRole('tech_admin');

    attempt();
    $this->artisan('sms:health-check')->assertSuccessful();

    // The whole point: an alert about a dead SMS pipe must not be routed down it.
    Notification::assertSentTo($admin, SmsHealthAlertNotification::class, function ($notification, array $channels) {
        return ! in_array(SmsChannel::class, $channels, true)
            && in_array('mail', $channels, true)
            && in_array('database', $channels, true);
    });
});

it('falls back to configured emails when nobody holds the permission', function () {
    // Production had zero users with view_system_health until 2026-07-31.
    Notification::fake();
    config()->set('services.sms.alert_emails', 'ops@cedibites.com, bad-address, second@cedibites.com');

    attempt();
    $this->artisan('sms:health-check')->assertSuccessful();

    Notification::assertSentOnDemand(SmsHealthAlertNotification::class);
    Notification::assertCount(2); // the malformed address is dropped
});

it('does not re-alert the same incident inside the cooldown', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    Notification::fake();

    $admin = User::factory()->create(['email' => 'tech@cedibites.com']);
    $admin->assignRole('tech_admin');

    attempt();

    $this->artisan('sms:health-check')->assertSuccessful();
    $this->artisan('sms:health-check')->assertSuccessful();
    $this->artisan('sms:health-check')->assertSuccessful();

    // A monitor that mails every run gets filtered, and then it is not a monitor.
    Notification::assertSentToTimes($admin, SmsHealthAlertNotification::class, 1);
});

it('re-alerts immediately when the cause changes', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    Notification::fake();

    $admin = User::factory()->create(['email' => 'tech@cedibites.com']);
    $admin->assignRole('tech_admin');

    attempt();
    $this->artisan('sms:health-check')->assertSuccessful();

    SmsDeliveryAttempt::query()->delete();
    attempt(['failure_reason' => SmsFailureReason::AuthFailed->value, 'error_message' => '401 Unauthorized']);
    $this->artisan('sms:health-check')->assertSuccessful();

    Notification::assertSentToTimes($admin, SmsHealthAlertNotification::class, 2);
});

it('sends a recovery notice once SMS works again', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    Notification::fake();

    $admin = User::factory()->create(['email' => 'tech@cedibites.com']);
    $admin->assignRole('tech_admin');

    attempt();
    $this->artisan('sms:health-check')->assertSuccessful();

    SmsDeliveryAttempt::query()->delete();
    foreach (range(1, 6) as $i) {
        SmsDeliveryAttempt::create(['recipient' => "23324123456{$i}", 'succeeded' => true]);
    }

    $this->artisan('sms:health-check')->assertSuccessful();

    Notification::assertSentTo($admin, SmsHealthAlertNotification::class, fn ($n) => $n->recovered === true);
});

it('stays quiet about recovery when it never raised an alarm', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    Notification::fake();

    $admin = User::factory()->create(['email' => 'tech@cedibites.com']);
    $admin->assignRole('tech_admin');

    SmsDeliveryAttempt::create(['recipient' => '233241234567', 'succeeded' => true]);

    $this->artisan('sms:health-check')->assertSuccessful();

    Notification::assertNothingSent();
});

it('prunes attempt rows past the retention window', function () {
    attempt(['created_at' => now()->subDays(40)]);
    attempt(['created_at' => now()->subDays(2)]);

    $this->artisan('sms:health-check --dry --prune-days=30')->assertSuccessful();

    expect(SmsDeliveryAttempt::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Endpoint
|--------------------------------------------------------------------------
*/

it('exposes sms health to a platform admin and masks the numbers', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('tech_admin');

    attempt(['recipient' => '233241234567']);

    $response = $this->actingAs($admin, 'sanctum')->getJson('/v1/platform/sms-health');

    $response->assertOk()
        ->assertJsonPath('data.status', 'critical')
        ->assertJsonPath('data.reason', 'no_credit');

    expect($response->json('data.recent_failures.0.recipient'))->not->toContain('241234');
});

it('refuses sms health to a non-platform user', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $this->actingAs($manager, 'sanctum')->getJson('/v1/platform/sms-health')->assertForbidden();
});
