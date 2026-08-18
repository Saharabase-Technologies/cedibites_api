<?php

use App\Enums\AutomationEvent;
use App\Models\AutomationFire;
use App\Models\AutomationRule;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The send is a separate concern with its own test file; faking the bus
    // keeps these about what the evaluator decides, and stops a sync queue
    // turning every assertion into a real HTTP call.
    Bus::fake();

    Order::query()->forceDelete();
    Customer::query()->forceDelete();
    User::query()->forceDelete();

    config([
        'automation.enabled' => true,
        'automation.cooldown_days' => 3,
        'order_feedback.enabled' => false,
    ]);
});

/**
 * An order that completes, so the observer evaluates it.
 *
 * Created as `received` then updated, because the evaluator hangs off the status
 * transition — an order created already complete never passes through it.
 */
function automationOrder(string $phone, array $attributes = []): Order
{
    $order = Order::factory()->create([
        'customer_id' => null,
        'contact_phone' => $phone,
        'status' => 'received',
        ...$attributes,
    ]);

    $order->update(['status' => 'completed']);

    return $order->fresh();
}

function ruleFor(AutomationEvent $event, array $attributes = []): AutomationRule
{
    return AutomationRule::factory()->create([
        'event' => $event->value,
        ...$attributes,
    ]);
}

// ─── Milestones ──────────────────────────────────────────────────────────────

it('fires on a first order and not on the second', function () {
    ruleFor(AutomationEvent::FirstOrder);

    automationOrder('+233241111111');
    expect(AutomationFire::notSuppressed()->count())->toBe(1);

    // Far enough ahead that the cooldown is not what stops it.
    $this->travelTo(now()->addDays(30));
    automationOrder('+233241111111');

    expect(AutomationFire::notSuppressed()->count())->toBe(1);
});

it('fires the first time somebody orders at a branch, even if they order elsewhere', function () {
    $ashaiman = Branch::factory()->create();
    $mother = Branch::factory()->create();

    ruleFor(AutomationEvent::FirstAtBranch);

    automationOrder('+233241111111', ['branch_id' => $ashaiman->id]);
    $this->travelTo(now()->addDays(30));
    automationOrder('+233241111111', ['branch_id' => $mother->id]);

    // Two branches, two firsts.
    expect(AutomationFire::notSuppressed()->count())->toBe(2);

    $this->travelTo(now()->addDays(30));
    automationOrder('+233241111111', ['branch_id' => $ashaiman->id]);

    expect(AutomationFire::notSuppressed()->count())->toBe(2);
});

it('fires on a first delivery, then not on later deliveries', function () {
    ruleFor(AutomationEvent::FirstOrderType);

    automationOrder('+233241111111', ['order_type' => 'delivery']);
    $this->travelTo(now()->addDays(30));
    automationOrder('+233241111111', ['order_type' => 'delivery']);

    expect(AutomationFire::notSuppressed()->count())->toBe(1);
});

it('fires when somebody tries an option they have never had', function () {
    $item = MenuItem::factory()->create();
    $large = MenuItemOption::factory()->create(['menu_item_id' => $item->id]);
    $small = MenuItemOption::factory()->create(['menu_item_id' => $item->id]);

    ruleFor(AutomationEvent::TriedSomethingNew);

    $first = automationOrder('+233241111111');
    OrderItem::factory()->create(['order_id' => $first->id, 'menu_item_id' => $item->id, 'menu_item_option_id' => $large->id]);

    // Their first order is not "trying something new" — everything is new, and
    // that belongs to the first-order rule.
    expect(AutomationFire::notSuppressed()->count())->toBe(0);

    $this->travelTo(now()->addDays(30));
    $second = Order::factory()->create([
        'customer_id' => null, 'contact_phone' => '+233241111111', 'status' => 'received',
    ]);
    OrderItem::factory()->create(['order_id' => $second->id, 'menu_item_id' => $item->id, 'menu_item_option_id' => $small->id]);
    $second->update(['status' => 'completed']);

    expect(AutomationFire::notSuppressed()->count())->toBe(1);
});

it('fires on the Nth order and no other', function () {
    ruleFor(AutomationEvent::NthOrder, ['event_config' => ['order_number' => 3]]);

    foreach (range(1, 4) as $i) {
        $this->travelTo(now()->addDays(30));
        automationOrder('+233241111111');
    }

    expect(AutomationFire::notSuppressed()->count())->toBe(1);
});

it('fires when somebody comes back after a gap, but never on a first order', function () {
    ruleFor(AutomationEvent::ReturnedAfterGap, ['event_config' => ['gap_days' => 30]]);

    automationOrder('+233241111111');
    // A first order is not a return. Firing a win-back at somebody who has
    // never left is the most obviously wrong message this could send.
    expect(AutomationFire::count())->toBe(0);

    $this->travelTo(now()->addDays(45));
    automationOrder('+233241111111');

    expect(AutomationFire::notSuppressed()->count())->toBe(1);
});

it('fires over a value threshold', function () {
    ruleFor(AutomationEvent::HighValueOrder, ['event_config' => ['minimum_amount' => 200]]);

    automationOrder('+233241111111', ['total_amount' => 150]);
    expect(AutomationFire::count())->toBe(0);

    automationOrder('+233242222222', ['total_amount' => 250]);
    expect(AutomationFire::notSuppressed()->count())->toBe(1);
});

// ─── Guardrails ──────────────────────────────────────────────────────────────

it('lets only one rule win an order, and records the others', function () {
    // A first order that is also a first delivery. Two reasonable rules, one
    // afternoon — the exact shape that makes automation annoying.
    ruleFor(AutomationEvent::FirstOrder, ['priority' => 10, 'name' => 'Welcome']);
    ruleFor(AutomationEvent::FirstOrderType, ['priority' => 50, 'name' => 'First delivery']);

    automationOrder('+233241111111', ['order_type' => 'delivery']);

    expect(AutomationFire::notSuppressed()->count())->toBe(1)
        ->and(AutomationFire::notSuppressed()->first()->rule->name)->toBe('Welcome')
        // Recorded, not dropped: "something else got there first" is a different
        // fact from "did not match".
        ->and(AutomationFire::where('suppressed_reason', AutomationFire::LOWER_PRIORITY)->count())->toBe(1);
});

it('holds the cooldown across different rules, not just within one', function () {
    // Per-rule cooldowns are the trap — three rules each waiting three days
    // still produce three texts in one afternoon.
    ruleFor(AutomationEvent::FirstOrder, ['priority' => 10]);
    ruleFor(AutomationEvent::HighValueOrder, ['priority' => 20, 'event_config' => ['minimum_amount' => 1]]);

    automationOrder('+233241111111', ['total_amount' => 500]);
    expect(AutomationFire::notSuppressed()->count())->toBe(1);

    // A different rule, a day later, same person.
    $this->travelTo(now()->addDay());
    automationOrder('+233241111111', ['total_amount' => 500]);

    expect(AutomationFire::notSuppressed()->count())->toBe(1)
        ->and(AutomationFire::where('suppressed_reason', AutomationFire::COOLDOWN)->exists())->toBeTrue();
});

it('lets the cooldown lapse', function () {
    ruleFor(AutomationEvent::HighValueOrder, ['event_config' => ['minimum_amount' => 1]]);

    automationOrder('+233241111111', ['total_amount' => 500]);
    $this->travelTo(now()->addDays(4));
    automationOrder('+233241111111', ['total_amount' => 500]);

    expect(AutomationFire::notSuppressed()->count())->toBe(2);
});

it('will not let a rule set a shorter cooldown than the house rule', function () {
    $rule = ruleFor(AutomationEvent::FirstOrder, ['cooldown_days' => 1]);

    expect($rule->effectiveCooldownDays())->toBe(3);
});

it('honours a lifetime cap', function () {
    ruleFor(AutomationEvent::HighValueOrder, [
        'event_config' => ['minimum_amount' => 1],
        'max_per_customer' => 1,
    ]);

    automationOrder('+233241111111', ['total_amount' => 500]);
    $this->travelTo(now()->addDays(30));
    automationOrder('+233241111111', ['total_amount' => 500]);

    expect(AutomationFire::notSuppressed()->count())->toBe(1)
        ->and(AutomationFire::where('suppressed_reason', AutomationFire::LIFETIME_CAP)->exists())->toBeTrue();
});

it('records but suppresses everything while the feature is switched off', function () {
    // The whole point of shipping dark: rules match real orders and the log
    // fills up, so a rule can earn trust before anybody turns it on.
    config(['automation.enabled' => false]);
    ruleFor(AutomationEvent::FirstOrder);

    automationOrder('+233241111111');

    expect(AutomationFire::count())->toBe(1)
        ->and(AutomationFire::first()->suppressed_reason)->toBe(AutomationFire::FEATURE_OFF)
        ->and(AutomationFire::notSuppressed()->count())->toBe(0);
});

it('ignores rules that are switched off', function () {
    ruleFor(AutomationEvent::FirstOrder, ['is_active' => false]);

    automationOrder('+233241111111');

    expect(AutomationFire::count())->toBe(0);
});

it('samples the same people every time rather than rolling a fresh dice', function () {
    // A fresh roll per evaluation would mean nobody is reliably excluded, the
    // cooldown stops protecting anyone in particular, and two dry runs disagree.
    $rule = ruleFor(AutomationEvent::FirstOrder, ['sample_rate' => 50]);
    $guard = app(\App\Services\Automation\AutomationGuard::class);

    $first = $guard->isSampled($rule, '+233241111111');

    foreach (range(1, 20) as $ignored) {
        expect($guard->isSampled($rule, '+233241111111'))->toBe($first);
    }
});

it('gives each rule a different slice of the base', function () {
    // Otherwise every rule talks to the same unlucky fifth.
    $a = ruleFor(AutomationEvent::FirstOrder, ['sample_rate' => 50]);
    $b = ruleFor(AutomationEvent::FirstOrder, ['sample_rate' => 50]);
    $guard = app(\App\Services\Automation\AutomationGuard::class);

    $phones = collect(range(1000000, 1000060))->map(fn ($n) => '+2332'.$n);

    $sampledByA = $phones->filter(fn ($p) => $guard->isSampled($a, $p));
    $sampledByB = $phones->filter(fn ($p) => $guard->isSampled($b, $p));

    expect($sampledByA->diff($sampledByB))->not->toBeEmpty();
});

// ─── Conditions ──────────────────────────────────────────────────────────────

it('applies audience conditions on top of the event', function () {
    $ashaiman = Branch::factory()->create();
    $mother = Branch::factory()->create();

    ruleFor(AutomationEvent::FirstOrder, [
        'audience_rules' => ['branch_ids' => [$ashaiman->id]],
    ]);

    automationOrder('+233241111111', ['branch_id' => $mother->id]);
    expect(AutomationFire::count())->toBe(0);

    automationOrder('+233242222222', ['branch_id' => $ashaiman->id]);
    expect(AutomationFire::notSuppressed()->count())->toBe(1);
});

it('counts the order that triggered the rule when testing conditions', function () {
    // At somebody's third order, "three orders or more" has to be true — the
    // milestone and the condition describe the same moment.
    ruleFor(AutomationEvent::NthOrder, [
        'event_config' => ['order_number' => 3],
        'audience_rules' => ['min_orders' => 3],
    ]);

    foreach (range(1, 3) as $i) {
        $this->travelTo(now()->addDays(30));
        automationOrder('+233241111111');
    }

    expect(AutomationFire::notSuppressed()->count())->toBe(1);
});

it('never evaluates an order that was cancelled', function () {
    ruleFor(AutomationEvent::FirstOrder);

    $order = Order::factory()->create([
        'customer_id' => null, 'contact_phone' => '+233241111111', 'status' => 'received',
    ]);
    $order->update(['status' => 'cancelled']);

    expect(AutomationFire::count())->toBe(0);
});
