<?php

use App\Enums\EmployeeStatus;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\TechErrorTextNotification;
use App\Services\Platform\RuntimeSettings;
use App\Services\Platform\TechErrorTexter;
use App\Services\SmartErrorService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    Notification::fake();
    // The real feed asks a model to explain unfamiliar log lines. Not from a test.
    Http::fake();
    Http::preventStrayRequests();
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    // Production turns this on by default; the test environment does not.
    config()->set('alerts.tech_errors.enabled', true);
    config()->set('app.frontend_url', 'https://app.cedibites.com');
    // Measured on whatever machine runs the suite, which says nothing about
    // the server. One test below turns it back on.
    config()->set('alerts.tech_errors.disk_full_percent', 100);

    $this->travelTo(Carbon::parse('2026-09-25 14:00:00'));
});

function textRecipient(array $user = [], EmployeeStatus $status = EmployeeStatus::Active): User
{
    $admin = User::factory()->create($user + ['phone' => '+233592123054']);
    $admin->assignRole('tech_admin');
    Employee::factory()->create(['user_id' => $admin->id, 'status' => $status]);

    return $admin;
}

/**
 * Stand in for the error feed, so these tests say exactly what the page is
 * showing instead of reading the real log file, which fills during a suite run.
 *
 * @param  array<int, array<string, mixed>>  $errors
 */
function feedShows(array $errors): void
{
    test()->partialMock(SmartErrorService::class, function ($mock) use ($errors) {
        $mock->shouldReceive('getFeed')->andReturn(['errors' => $errors, 'summary' => []]);
    });
}

function fault(string $title, array $extra = []): array
{
    return $extra + [
        'category' => 'system',
        'severity' => 'error',
        'title' => $title,
        'timestamp' => now()->subMinutes(2)->toIso8601String(),
    ];
}

function failedSignIn(string $name, string $reason, int $minutesAgo = 5): void
{
    $entry = activity('auth')
        ->event('staff_login_failed')
        ->withProperties(['identifier' => Str::slug($name), 'user_id' => crc32($name), 'name' => $name, 'reason' => $reason])
        ->log("Failed staff login for {$name} ({$reason})");

    $entry->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();
}

/**
 * What reached this person, read as production would send it. Outside
 * production every text is tagged with the environment; one test checks that.
 */
function texts(User $to): Illuminate\Support\Collection
{
    return Notification::sent($to, TechErrorTextNotification::class)
        ->map(fn ($n) => str_replace('CediBites (testing):', 'CediBites:', $n->message))
        ->values();
}

/*
|--------------------------------------------------------------------------
| A new fault
|--------------------------------------------------------------------------
*/

it('texts a new fault to the tech admin with a link to the error page', function () {
    $admin = textRecipient();
    feedShows([fault('Numeric field overflow saving an order')]);

    $this->artisan('alerts:text-tech-errors')->assertSuccessful();

    expect(texts($admin)->all())->toBe([
        'CediBites: Numeric field overflow saving an order. Seen at 1:58 pm. https://app.cedibites.com/admin/platform/errors',
    ]);
});

it('fits a text into one SMS and keeps it plain', function () {
    $admin = textRecipient();
    feedShows([fault(str_repeat('A very long explanation — with “quotes” ', 10))]);

    $this->artisan('alerts:text-tech-errors');
    $message = texts($admin)->sole();

    // One curly quote turns the whole text to Unicode: 70 characters a text
    // instead of 160.
    expect(mb_check_encoding($message, 'ASCII'))->toBeTrue()
        ->and(strlen($message))->toBeLessThanOrEqual(160);
});

it('counts every sighting of the same fault in one text', function () {
    $admin = textRecipient();
    feedShows([
        fault('Call to load() on null in OrderController', ['timestamp' => now()->subMinutes(20)->toIso8601String()]),
        fault('Call to load() on null in OrderController', ['timestamp' => now()->subMinutes(9)->toIso8601String()]),
        fault('Call to load() on null in OrderController', ['timestamp' => now()->subMinutes(1)->toIso8601String()]),
    ]);

    $this->artisan('alerts:text-tech-errors');

    expect(texts($admin))->toHaveCount(1)
        ->and(texts($admin)->sole())->toContain('Seen 3 times since 1:40 pm.');
});

it('leaves out old news, so a deploy does not text yesterday', function () {
    $admin = textRecipient();
    feedShows([fault('Something from this morning', ['timestamp' => now()->subHours(3)->toIso8601String()])]);

    $this->artisan('alerts:text-tech-errors');

    expect(texts($admin))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Reminders
|--------------------------------------------------------------------------
*/

it('does not text the same fault again inside the reminder window', function () {
    $admin = textRecipient();
    feedShows([fault('Queue job failed')]);
    $this->artisan('alerts:text-tech-errors');

    $this->travel(2)->hours();
    feedShows([fault('Queue job failed')]);
    $this->artisan('alerts:text-tech-errors');

    expect(texts($admin))->toHaveCount(1);
});

it('reminds after three hours when the fault has kept happening', function () {
    $admin = textRecipient();
    feedShows([fault('Queue job failed')]);
    $this->artisan('alerts:text-tech-errors');

    // Three and a half hours on, it has happened twice more since the text.
    $this->travel(210)->minutes();
    feedShows([
        fault('Queue job failed', ['timestamp' => Carbon::parse('2026-09-25 13:58:00')->toIso8601String()]),
        fault('Queue job failed', ['timestamp' => Carbon::parse('2026-09-25 15:10:00')->toIso8601String()]),
        fault('Queue job failed', ['timestamp' => Carbon::parse('2026-09-25 16:45:00')->toIso8601String()]),
    ]);
    $this->artisan('alerts:text-tech-errors');

    expect(texts($admin))->toHaveCount(2)
        ->and(texts($admin)->last())->toBe(
            'CediBites: Still happening: Queue job failed. Happened 2 more times since 2:00 pm. https://app.cedibites.com/admin/platform/errors'
        );
});

it('does not chase a fault that has stopped', function () {
    $admin = textRecipient();
    feedShows([fault('Queue job failed')]);
    $this->artisan('alerts:text-tech-errors');

    // Past the window, but the only sighting is the one already texted.
    $this->travel(4)->hours();
    $this->artisan('alerts:text-tech-errors');

    expect(texts($admin))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| What is left out
|--------------------------------------------------------------------------
*/

it('leaves out what is not a fault or cannot travel by text', function () {
    $admin = textRecipient();
    $this->travelTo(Carbon::parse('2026-09-25 06:45:00'));
    feedShows([
        // Below a warning on the page.
        fault('Ama has had 5 failed sign-ins today', ['severity' => 'info', 'category' => 'authentication']),
        // The SMS line reporting itself down, down the same line.
        fault('SMS notification failed', ['raw' => 'SMS API Error: Payment required on account']),
        fault('An SMS failed to send', ['category' => 'notifications']),
        // Somebody's typing mistake in tinker on the server.
        fault('A PHP parse error', ['raw' => 'PHP Parse error: Syntax error {"exception":"[object] (Psy\\Exception\\ParseErrorException']),
        // The server patching itself at 06:41 and restarting Postgres.
        fault('The database refused the connection', [
            'raw' => 'SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: Connection refused',
            'timestamp' => Carbon::parse('2026-09-25 06:41:00')->toIso8601String(),
        ]),
    ]);

    $this->artisan('alerts:text-tech-errors');

    expect(texts($admin))->toBeEmpty();
});

it('still texts the database refusing connections outside the patch window', function () {
    $admin = textRecipient();
    feedShows([fault('The database refused the connection', [
        'raw' => 'SQLSTATE[08006] connection to server failed: Connection refused',
    ])]);

    $this->artisan('alerts:text-tech-errors');

    expect(texts($admin))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Failed sign-ins
|--------------------------------------------------------------------------
*/

it('texts a burst of failed sign-ins straight away, which the page calls a warning', function () {
    $admin = textRecipient();
    feedShows([fault('Rosina failed to sign in 3 times in 5 minutes', [
        'severity' => 'warning', 'category' => 'authentication', 'count' => 3,
    ])]);

    $this->artisan('alerts:text-tech-errors');

    expect(texts($admin)->first())->toContain('Rosina failed to sign in 3 times in 5 minutes. Seen 3 times');
});

it('rounds up failed sign-ins by person and reason', function () {
    $admin = textRecipient();
    feedShows([]);
    failedSignIn('Rosina', 'wrong_password', 40);
    failedSignIn('Rosina', 'wrong_password', 30);
    failedSignIn('Kofi', 'account_suspended', 10);

    $this->artisan('alerts:text-tech-errors');

    expect(texts($admin)->all())->toBe([
        'CediBites: 3 failed staff sign-ins since 1:20 pm. Rosina 2 (wrong password), Kofi 1 (account suspended). https://app.cedibites.com/admin/platform/errors',
    ]);
});

it('sends the round-up at most every six hours, and only with something new', function () {
    $admin = textRecipient();
    feedShows([]);
    failedSignIn('Rosina', 'wrong_password');
    $this->artisan('alerts:text-tech-errors');

    // More failures an hour later wait for the window.
    $this->travel(1)->hours();
    failedSignIn('Kofi', 'wrong_password', 1);
    $this->artisan('alerts:text-tech-errors');
    expect(texts($admin))->toHaveCount(1);

    // Six hours after the first round-up, Kofi's failure goes out on its own.
    $this->travel(5)->hours();
    $this->artisan('alerts:text-tech-errors');
    expect(texts($admin))->toHaveCount(2)
        ->and(texts($admin)->last())->toContain('1 failed staff sign-in since 2:59 pm. Kofi 1 (wrong password).');

    // And nothing at all when nobody has failed since.
    $this->travel(7)->hours();
    $this->artisan('alerts:text-tech-errors');
    expect(texts($admin))->toHaveCount(2);
});

it('names three people and counts the rest', function () {
    $admin = textRecipient();
    feedShows([]);
    foreach (['Ama', 'Kofi', 'Esi', 'Yaw', 'Akos'] as $i => $name) {
        failedSignIn($name, 'wrong_password', 10 + $i);
    }

    $this->artisan('alerts:text-tech-errors');

    expect(texts($admin)->sole())->toContain(', and 2 others.');
});

/*
|--------------------------------------------------------------------------
| Limits and switches
|--------------------------------------------------------------------------
*/

it('stops at the daily limit and says how many more are waiting, once', function () {
    $admin = textRecipient();
    app(RuntimeSettings::class)->set('alerts.tech_error_daily_cap', 3);

    feedShows(collect(['First', 'Second', 'Third', 'Fourth', 'Fifth'])
        // Distinct in words: the fingerprint ignores digits.
        ->map(fn ($word) => fault("{$word} distinct fault"))
        ->all());

    $this->artisan('alerts:text-tech-errors');
    $this->travel(5)->minutes();
    $this->artisan('alerts:text-tech-errors');

    // Three faults, then one notice, and the second run adds nothing.
    expect(texts($admin))->toHaveCount(4)
        ->and(texts($admin)->last())->toContain('3 error texts today, the most allowed. 2 more are waiting.');
});

it('spends the limit on the worst fault first', function () {
    $admin = textRecipient();
    app(RuntimeSettings::class)->set('alerts.tech_error_daily_cap', 1);

    feedShows([
        fault('A warning about something small', ['severity' => 'warning']),
        fault('The queue has stopped', ['severity' => 'critical']),
    ]);

    $this->artisan('alerts:text-tech-errors');

    expect(texts($admin)->first())->toContain('The queue has stopped');
});

it('sends nothing when switched off from the panel', function () {
    $admin = textRecipient();
    app(RuntimeSettings::class)->set('alerts.tech_error_texts', false);
    feedShows([fault('Queue job failed')]);

    $this->artisan('alerts:text-tech-errors')->expectsOutputToContain('switched off');

    expect(texts($admin))->toBeEmpty();
});

it('shows what it would send on a dry run and sends nothing, even while off', function () {
    $admin = textRecipient();
    app(RuntimeSettings::class)->set('alerts.tech_error_texts', false);
    feedShows([fault('Queue job failed')]);

    $this->artisan('alerts:text-tech-errors --dry')
        ->expectsOutputToContain('would text')
        ->assertSuccessful();

    expect(texts($admin))->toBeEmpty()
        ->and(ActivityLog::where('event', TechErrorTexter::EVENT_TEXTED)->count())->toBe(0);
});

it('says which server it came from when that is not production', function () {
    $admin = textRecipient();
    feedShows([fault('Queue job failed')]);

    $this->artisan('alerts:text-tech-errors');

    Notification::assertSentTo($admin, TechErrorTextNotification::class,
        fn ($n) => str_starts_with($n->message, 'CediBites (testing): Queue job failed.'));
});

it('is off by default outside production', function () {
    // config/alerts.php reads APP_ENV, which is "testing" here.
    expect(require base_path('config/alerts.php'))
        ->toHaveKey('tech_errors.enabled', false);
});

/*
|--------------------------------------------------------------------------
| Who, and the record
|--------------------------------------------------------------------------
*/

it('texts only people who can read system health, have a phone, and still work here', function () {
    $current = textRecipient();
    $gone = textRecipient(['phone' => '+233200000001'], EmployeeStatus::Terminated);
    $noPhone = textRecipient(['phone' => null]);
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    feedShows([fault('Queue job failed')]);
    $this->artisan('alerts:text-tech-errors');

    Notification::assertSentTo($current, TechErrorTextNotification::class);
    Notification::assertNotSentTo([$gone, $noPhone, $manager], TechErrorTextNotification::class);
});

it('records every text in the activity log', function () {
    textRecipient();
    feedShows([fault('Queue job failed')]);

    $this->artisan('alerts:text-tech-errors');

    $entry = ActivityLog::where('event', TechErrorTexter::EVENT_TEXTED)->sole();
    expect($entry->log_name)->toBe('platform')
        ->and($entry->properties['title'])->toBe('Queue job failed')
        ->and($entry->properties['recipients'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Checks the feed does not make
|--------------------------------------------------------------------------
*/

it('texts about a queue nobody is working', function () {
    $admin = textRecipient();
    feedShows([]);
    config()->set('queue.default', 'database');

    foreach ([25, 14] as $minutesAgo) {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes($minutesAgo)->getTimestamp(),
            'created_at' => now()->subMinutes($minutesAgo)->getTimestamp(),
        ]);
    }

    $this->artisan('alerts:text-tech-errors');

    expect(texts($admin)->sole())->toContain('The queue has stopped. 2 jobs waiting, the oldest for 25 minutes');
});

it('texts about a disk nearly full', function () {
    $admin = textRecipient();
    feedShows([]);
    config()->set('alerts.tech_errors.disk_full_percent', 0);

    $this->artisan('alerts:text-tech-errors');

    expect(texts($admin)->sole())->toMatch('/The server disk is \d+% full\. Seen at 2:00 pm\./');
});

it('does not call a delayed job stalled before it is due', function () {
    $admin = textRecipient();
    feedShows([]);
    config()->set('queue.default', 'database');

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->addMinutes(30)->getTimestamp(),
        'created_at' => now()->subHour()->getTimestamp(),
    ]);

    $this->artisan('alerts:text-tech-errors');

    expect(texts($admin))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Wiring
|--------------------------------------------------------------------------
*/

it('reads the real error feed end to end', function () {
    $admin = textRecipient();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\RecomputeSmartCategories']),
        'exception' => "RuntimeException: The smart category run blew up\n#0 stack",
        'failed_at' => now()->subMinute(),
    ]);

    $this->artisan('alerts:text-tech-errors');

    // Other tests in the suite write to the real log, so this asserts that the
    // job arrived, not that nothing else did.
    expect(texts($admin)->contains(fn ($m) => str_contains($m, 'RecomputeSmartCategories job failed. Seen at 1:59 pm.')))
        ->toBeTrue();
});

it('is on the schedule every five minutes', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('alerts:text-tech-errors');
});
