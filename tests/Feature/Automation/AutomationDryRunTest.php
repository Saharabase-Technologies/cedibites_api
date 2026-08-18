<?php

use App\Enums\AutomationEvent;
use App\Models\AutomationFire;
use App\Models\AutomationRule;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Automation\AutomationDryRun;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Order::query()->forceDelete();
    Customer::query()->forceDelete();
    User::query()->forceDelete();

    config([
        // Off: a dry run must work with the feature down, which is the whole
        // point of being able to run it before switching anything on.
        'automation.enabled' => false,
        'automation.cooldown_days' => 3,
        'automation.dry_run_days' => 30,
        'automation.rate_per_segment' => 0.0243,
        'order_feedback.enabled' => false,
    ]);
});

/** A completed order at a point in the past, without firing the observer. */
function pastOrder(string $phone, int $daysAgo, array $attributes = []): Order
{
    return Order::withoutEvents(fn () => Order::factory()->create([
        'customer_id' => null,
        'contact_phone' => $phone,
        'status' => 'completed',
        'created_at' => now()->subDays($daysAgo),
        ...$attributes,
    ]));
}

function dryRunRule(AutomationEvent $event, array $attributes = []): AutomationRule
{
    return AutomationRule::factory()->create(['event' => $event->value, ...$attributes]);
}

it('reports what a rule would have done without sending or recording anything', function () {
    pastOrder('+233241111111', 10);
    pastOrder('+233242222222', 8);
    pastOrder('+233243333333', 5);

    $result = app(AutomationDryRun::class)->run(dryRunRule(AutomationEvent::FirstOrder));

    expect($result['matched'])->toBe(3)
        ->and($result['would_send'])->toBe(3)
        ->and($result['people_reached'])->toBe(3)
        // Nothing written. A dry run that logged would poison the cooldown for
        // the real rule the moment it was switched on.
        ->and(AutomationFire::count())->toBe(0);
});

it('prices the send from the message, not from a guess', function () {
    pastOrder('+233241111111', 5);
    pastOrder('+233242222222', 4);

    $result = app(AutomationDryRun::class)->run(
        dryRunRule(AutomationEvent::FirstOrder, ['message' => 'Plain text, one segment.']),
    );

    expect($result['segments_per_message'])->toBe(1)
        ->and($result['estimated_cost'])->toBe(round(2 * 0.0243, 4));
});

it('applies the cooldown across the replay, in the direction time runs', function () {
    // Same person, three qualifying orders inside the cooldown. A replay that
    // ignored the cooldown would promise three sends and deliver one.
    pastOrder('+233241111111', 10, ['total_amount' => 500]);
    pastOrder('+233241111111', 9, ['total_amount' => 500]);
    pastOrder('+233241111111', 8, ['total_amount' => 500]);

    $result = app(AutomationDryRun::class)->run(
        dryRunRule(AutomationEvent::HighValueOrder, ['event_config' => ['minimum_amount' => 100]]),
    );

    expect($result['matched'])->toBe(3)
        ->and($result['would_send'])->toBe(1)
        ->and($result['suppressed'][AutomationFire::COOLDOWN])->toBe(2);
});

it('reports the busiest recipient, so the cooldown can be judged', function () {
    // A rule reaching three people forty times is a different animal from one
    // reaching three hundred people forty times, and the totals cannot tell
    // them apart.
    foreach ([20, 16, 12, 8, 4] as $daysAgo) {
        pastOrder('+233241111111', $daysAgo, ['total_amount' => 500]);
    }
    pastOrder('+233242222222', 6, ['total_amount' => 500]);

    $result = app(AutomationDryRun::class)->run(
        dryRunRule(AutomationEvent::HighValueOrder, ['event_config' => ['minimum_amount' => 100]]),
    );

    expect($result['busiest_recipient'])->toBe(5)
        ->and($result['people_reached'])->toBe(2);
});

it('respects sampling, and gives the same answer every run', function () {
    foreach (range(1, 40) as $i) {
        pastOrder('+2332410000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 10);
    }

    $rule = dryRunRule(AutomationEvent::FirstOrder, ['sample_rate' => 50]);

    $first = app(AutomationDryRun::class)->run($rule);
    $second = app(AutomationDryRun::class)->run($rule);

    expect($first['would_send'])->toBe($second['would_send'])
        ->and($first['would_send'])->toBeLessThan($first['matched'])
        ->and($first['suppressed'][AutomationFire::NOT_SAMPLED])->toBeGreaterThan(0);
});

it('looks no further back than the window', function () {
    pastOrder('+233241111111', 5);
    pastOrder('+233242222222', 90);

    $result = app(AutomationDryRun::class)->run(dryRunRule(AutomationEvent::FirstOrder), days: 30);

    expect($result['matched'])->toBe(1);
});

it('runs from the command line', function () {
    pastOrder('+233241111111', 5);
    $rule = dryRunRule(AutomationEvent::FirstOrder, ['name' => 'Welcome']);

    $this->artisan("automation:dry-run {$rule->id}")
        ->expectsOutputToContain('Welcome')
        ->expectsOutputToContain('Nothing was sent')
        ->assertSuccessful();
});
