<?php

use App\Enums\EmployeeStatus;
use App\Models\AcknowledgedError;
use App\Models\Employee;
use App\Models\User;
use App\Services\SmartErrorService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    Cache::flush();
    config()->set('services.error_explainer.key', null);
});

const ACK_PASSCODE = '654321';

function platformAdmin(): User
{
    test()->seed(PermissionSeeder::class);
    test()->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'platform_passcode' => Hash::make(ACK_PASSCODE),
    ]);

    Employee::factory()->create([
        'user_id' => $user->id,
        'status' => EmployeeStatus::Active,
    ]);

    $user->assignRole('tech_admin');

    return $user->fresh();
}

function failedJob(string $uuid, ?string $failedAt = null): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SendOrderNotification']),
        'exception' => "No query results for model [App\\Models\\Order] 1042\nstack trace here",
        'failed_at' => $failedAt ?? now()->toDateTimeString(),
    ]);
}


/**
 * The feed item for a failed job.
 *
 * Not `->first()`: the feed also reads `storage/logs/laravel.log`, which fills
 * up over a full suite run, so the newest item is often a stray log exception
 * from an unrelated test. `job_id` is set only by the failed-jobs source.
 */
function feedJobItem(array $errors): array
{
    $item = collect($errors)->first(fn ($e) => isset($e['job_id']));

    expect($item)->not->toBeNull('expected a failed-job item in the feed');

    return $item;
}

/*
|--------------------------------------------------------------------------
| Fingerprints
|--------------------------------------------------------------------------
*/

it('gives the same fault the same fingerprint however many times it happened', function () {
    $service = app(SmartErrorService::class);

    // The feed's own id carries the attempt count and a positional index, so
    // acknowledging by id would silence one sighting and let the next through
    // looking brand new.
    $first = $service->fingerprint([
        'category' => 'authentication',
        'title' => 'Ama failed to sign in 3 times in 5 minutes',
    ]);

    $second = $service->fingerprint([
        'category' => 'authentication',
        'title' => 'Ama failed to sign in 7 times in 5 minutes',
    ]);

    expect($first)->toBe($second);
});

it('keeps different faults apart', function () {
    $service = app(SmartErrorService::class);

    $login = $service->fingerprint(['category' => 'authentication', 'title' => 'Ama failed to sign in 3 times']);
    $payment = $service->fingerprint(['category' => 'payment', 'title' => 'Ama failed to sign in 3 times']);
    $other = $service->fingerprint(['category' => 'authentication', 'title' => 'Kofi failed to sign in 3 times']);

    expect($login)->not->toBe($payment)
        ->and($login)->not->toBe($other);
});

/*
|--------------------------------------------------------------------------
| Acknowledging
|--------------------------------------------------------------------------
*/

it('takes an acknowledged fault off the feed', function () {
    $admin = platformAdmin();
    failedJob('job-uuid-1');

    $before = $this->actingAs($admin, 'sanctum')->getJson('/v1/platform/errors');
    $item = feedJobItem($before->json('data.errors'));

    expect($item['acknowledged'])->toBeFalse();

    $this->actingAs($admin, 'sanctum')->postJson('/v1/platform/errors/acknowledge', [
        'fingerprint' => $item['fingerprint'],
        'title' => $item['title'],
        'category' => $item['category'],
        'severity' => $item['severity'],
    ])->assertOk();

    $after = $this->actingAs($admin, 'sanctum')->getJson('/v1/platform/errors');

    expect(collect($after->json('data.errors'))->pluck('fingerprint'))
        ->not->toContain($item['fingerprint'])
        ->and($after->json('data.summary.acknowledged'))->toBeGreaterThanOrEqual(1);
});

it('brings the fault back the moment it happens again', function () {
    // This is what separates "I have dealt with this one" from "never show me
    // this again". Without it the feed goes quiet on a fault that is still
    // happening.
    $admin = platformAdmin();
    failedJob('job-uuid-1', now()->subHour()->toDateTimeString());

    $item = feedJobItem(
        $this->actingAs($admin, 'sanctum')->getJson('/v1/platform/errors')->json('data.errors')
    );

    $this->actingAs($admin, 'sanctum')->postJson('/v1/platform/errors/acknowledge', [
        'fingerprint' => $item['fingerprint'],
        'title' => $item['title'],
        'category' => $item['category'],
        'severity' => $item['severity'],
    ])->assertOk();

    // The same job fails again, after the dismissal.
    failedJob('job-uuid-2', now()->addMinute()->toDateTimeString());

    $after = $this->actingAs($admin, 'sanctum')->getJson('/v1/platform/errors');

    expect(collect($after->json('data.errors'))->pluck('fingerprint'))
        ->toContain($item['fingerprint']);
});

it('can put a fault back by hand', function () {
    $admin = platformAdmin();
    failedJob('job-uuid-1');

    $item = feedJobItem(
        $this->actingAs($admin, 'sanctum')->getJson('/v1/platform/errors')->json('data.errors')
    );

    $this->actingAs($admin, 'sanctum')->postJson('/v1/platform/errors/acknowledge', [
        'fingerprint' => $item['fingerprint'],
        'title' => $item['title'],
    ])->assertOk();

    $this->actingAs($admin, 'sanctum')->postJson('/v1/platform/errors/unacknowledge', [
        'fingerprint' => $item['fingerprint'],
    ])->assertOk();

    expect(AcknowledgedError::count())->toBe(0);

    $after = $this->actingAs($admin, 'sanctum')->getJson('/v1/platform/errors');
    expect(collect($after->json('data.errors'))->pluck('fingerprint'))->toContain($item['fingerprint']);
});

it('shows acknowledged items again when asked for', function () {
    $admin = platformAdmin();
    failedJob('job-uuid-1');

    $item = feedJobItem(
        $this->actingAs($admin, 'sanctum')->getJson('/v1/platform/errors')->json('data.errors')
    );

    $this->actingAs($admin, 'sanctum')->postJson('/v1/platform/errors/acknowledge', [
        'fingerprint' => $item['fingerprint'],
        'title' => $item['title'],
    ])->assertOk();

    $withAcked = $this->actingAs($admin, 'sanctum')
        ->getJson('/v1/platform/errors?include_acknowledged=1');

    $found = collect($withAcked->json('data.errors'))->firstWhere('fingerprint', $item['fingerprint']);

    expect($found)->not->toBeNull()
        ->and($found['acknowledged'])->toBeTrue()
        ->and($found['acknowledged_by'])->toBe($admin->name);
});

it('clears only the items the reader could actually see', function () {
    $admin = platformAdmin();
    failedJob('job-uuid-1');

    $item = feedJobItem(
        $this->actingAs($admin, 'sanctum')->getJson('/v1/platform/errors')->json('data.errors')
    );

    $this->actingAs($admin, 'sanctum')->postJson('/v1/platform/errors/acknowledge-all', [
        'errors' => [[
            'fingerprint' => $item['fingerprint'],
            'title' => $item['title'],
            'category' => $item['category'],
            'severity' => $item['severity'],
        ]],
    ])->assertOk();

    expect(AcknowledgedError::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Clearing the failed queue
|--------------------------------------------------------------------------
*/

it('drops one failed job from the queue', function () {
    $admin = platformAdmin();
    failedJob('job-uuid-1');
    failedJob('job-uuid-2');

    $this->actingAs($admin, 'sanctum')->postJson('/v1/platform/failed-jobs/forget', [
        'uuid' => 'job-uuid-1',
        'passcode' => ACK_PASSCODE,
    ])->assertOk();

    expect(DB::table('failed_jobs')->pluck('uuid')->all())->toBe(['job-uuid-2']);
});

it('will not clear a job without the passcode', function () {
    $admin = platformAdmin();
    failedJob('job-uuid-1');

    $this->actingAs($admin, 'sanctum')->postJson('/v1/platform/failed-jobs/forget', [
        'uuid' => 'job-uuid-1',
        'passcode' => '000000',
    ])->assertStatus(403);

    expect(DB::table('failed_jobs')->count())->toBe(1);
});

it('empties the whole failed queue', function () {
    $admin = platformAdmin();
    failedJob('job-uuid-1');
    failedJob('job-uuid-2');

    $this->actingAs($admin, 'sanctum')->postJson('/v1/platform/failed-jobs/flush', [
        'passcode' => ACK_PASSCODE,
    ])->assertOk();

    expect(DB::table('failed_jobs')->count())->toBe(0);
});

it('reports the true size of the backlog, not the size of the page', function () {
    // A queue of hundreds reading as "50" is how a backlog stops being alarming.
    $admin = platformAdmin();

    for ($i = 0; $i < 55; $i++) {
        failedJob("job-uuid-{$i}");
    }

    $response = $this->actingAs($admin, 'sanctum')->getJson('/v1/platform/failed-jobs');

    expect($response->json('meta.total'))->toBe(55)
        ->and($response->json('data'))->toHaveCount(50);
});
