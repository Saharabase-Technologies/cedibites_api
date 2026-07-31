<?php

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\User;
use App\Services\ErrorExplainer;
use App\Services\SmartErrorService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Cache::flush();
    config()->set('services.error_explainer.key', null);
});

/*
|--------------------------------------------------------------------------
| The Route [login] regression
|--------------------------------------------------------------------------
*/

it('answers an expired session with 401 instead of the login-route crash', function () {
    // A request without `Accept: application/json` used to take Laravel's
    // redirect-to-login path, and this API defines no `login` route — so the
    // most ordinary event there is (an expired token) returned 500 and filled
    // the error feed with "Route [login] not defined".
    $response = $this->get('/v1/platform/health');

    $response->assertStatus(401)
        ->assertJsonPath('error', 'unauthenticated');

    expect($response->getContent())->not->toContain('Route [login] not defined');
});

it('still answers JSON clients with 401', function () {
    $this->getJson('/v1/platform/health')->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| Explanations
|--------------------------------------------------------------------------
*/

it('explains known errors from the table without calling the model', function () {
    Http::fake();
    config()->set('services.error_explainer.key', 'test-key');

    $result = app(ErrorExplainer::class)->explain('Route [login] not defined.');

    expect($result['source'])->toBe('known')
        ->and($result['title'])->not->toContain('Route')
        ->and($result['fix'])->not->toBe('');

    // A model asked about this would talk about routing and miss that it is an
    // expired token. Known errors must never reach it.
    Http::assertNothingSent();
});

it('tells an operator to top up the SMS account', function () {
    $result = app(ErrorExplainer::class)->explain('SMS API Error: Payment required on account');

    expect($result['title'])->toContain('credit')
        ->and($result['fix'])->toContain('hubtel.com')
        ->and($result['category'])->toBe('integrations');
});

it('falls back without a key rather than failing', function () {
    Http::fake();

    $result = app(ErrorExplainer::class)->explain('Something nobody has ever seen before');

    expect($result['source'])->toBe('fallback');
    Http::assertNothingSent();
});

it('asks the model about an unrecognised error', function () {
    config()->set('services.error_explainer.key', 'test-key');
    Http::fake(['api.groq.com/*' => Http::response([
        'choices' => [[
            'message' => ['content' => json_encode([
                'title' => 'The kitchen printer stopped responding',
                'cause' => 'The printer did not answer.',
                'fix' => 'Check the printer is on.',
            ])],
        ]],
    ])]);

    $result = app(ErrorExplainer::class)->explain('PrinterOfflineException: no response from device');

    expect($result['source'])->toBe('ai')
        ->and($result['title'])->toBe('The kitchen printer stopped responding')
        ->and($result['fix'])->toBe('Check the printer is on.');
});

it('caches by signature so a repeating error costs one call', function () {
    config()->set('services.error_explainer.key', 'test-key');
    Http::fake(['api.groq.com/*' => Http::response([
        'choices' => [['message' => ['content' => json_encode([
            'title' => 't', 'cause' => 'c', 'fix' => 'f',
        ])]]],
    ])]);

    $explainer = app(ErrorExplainer::class);

    // Same error, different ids — the signature normalises digits away.
    $explainer->explain('WidgetException: widget 481 failed');
    $explainer->explain('WidgetException: widget 902 failed');
    $explainer->explain('WidgetException: widget 771 failed');

    Http::assertSentCount(1);
});

it('falls back when the model returns something unusable', function () {
    config()->set('services.error_explainer.key', 'test-key');
    Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => 'not json']]]])]);

    expect(app(ErrorExplainer::class)->explain('Totally novel failure')['source'])->toBe('fallback');
});

it('never throws when the model is unreachable', function () {
    config()->set('services.error_explainer.key', 'test-key');
    Http::fake(fn () => throw new RuntimeException('network down'));

    // An error dashboard that breaks on errors is worse than no dashboard.
    expect(app(ErrorExplainer::class)->explain('Another novel failure')['source'])->toBe('fallback');
});

/*
|--------------------------------------------------------------------------
| Login failure detail
|--------------------------------------------------------------------------
*/

function staffMember(string $password = 'correct-horse', EmployeeStatus $status = EmployeeStatus::Active): User
{
    $user = User::factory()->create([
        'phone' => '+233241111111',
        'password' => Hash::make($password),
    ]);

    Employee::factory()->create(['user_id' => $user->id, 'status' => $status]);

    return $user->fresh();
}

it('records why a sign-in failed, not just that it did', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    staffMember();

    $this->postJson('/v1/employee/login', [
        'identifier' => '+233241111111',
        'password' => 'wrong-password',
    ])->assertStatus(401);

    $activity = Activity::where('event', 'staff_login_failed')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['reason'])->toBe('wrong_password')
        ->and($activity->properties['name'])->not->toBeNull()
        ->and($activity->properties['user_id'])->not->toBeNull();
});

it('distinguishes an unknown account from a wrong password', function () {
    $this->postJson('/v1/employee/login', [
        'identifier' => '+233249999999',
        'password' => 'anything',
    ])->assertStatus(401);

    $activity = Activity::where('event', 'staff_login_failed')->latest('id')->first();

    expect($activity->properties['reason'])->toBe('unknown_account')
        ->and($activity->properties['user_id'])->toBeNull();
});

it('records a suspended account that had the right password', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    staffMember('correct-horse', EmployeeStatus::Suspended);

    $this->postJson('/v1/employee/login', [
        'identifier' => '+233241111111',
        'password' => 'correct-horse',
    ])->assertStatus(403);

    // This path returned 403 and logged nothing at all before, so "suspended
    // staff member still trying to get in" never reached the admin.
    $activity = Activity::where('event', 'staff_login_failed')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['reason'])->toBe('account_suspended');
});

it('surfaces who failed and why in the feed', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    staffMember();

    foreach (range(1, 3) as $i) {
        $this->postJson('/v1/employee/login', [
            'identifier' => '+233241111111',
            'password' => 'wrong-password',
        ]);
    }

    $feed = app(SmartErrorService::class)->getFeed();
    $burst = collect($feed['errors'])->firstWhere('category', 'authentication');

    expect($burst['reason'])->toBe('wrong_password')
        ->and($burst['name'])->not->toBeNull()
        ->and($burst['fix'])->toContain('Platform')
        ->and($burst['ips'])->not->toBeEmpty();
});

it('breaks the daily summary down by account and cause', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    staffMember();

    $this->postJson('/v1/employee/login', ['identifier' => '+233241111111', 'password' => 'nope']);
    $this->postJson('/v1/employee/login', ['identifier' => '+233249999999', 'password' => 'nope']);

    $feed = app(SmartErrorService::class)->getFeed();
    $summary = collect($feed['errors'])->firstWhere('id', 'login-summary-'.now()->format('Y-m-d'));

    expect($summary['cause'])->toContain('wrong password')
        ->and($summary['cause'])->toContain('no account')
        ->and($summary['accounts'])->toHaveCount(2);
});
