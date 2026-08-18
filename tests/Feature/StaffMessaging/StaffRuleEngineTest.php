<?php

use App\Enums\Role as RoleEnum;
use App\Enums\StaffMessageEvent;
use App\Enums\StaffMessageSuppression;
use App\Enums\StaffMessageTarget;
use App\Events\StaffMessageEvent as StaffMessageBroadcast;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\StaffMessage;
use App\Models\StaffMessageRule;
use App\Models\StaffMessageRuleFire;
use App\Services\StaffMessaging\StaffRuleDryRun;
use App\Services\StaffMessaging\StaffRuleEvaluator;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    Notification::fake();
    Event::fake([StaffMessageBroadcast::class]);
    config()->set('staff_messaging.automation_enabled', true);

    $this->branch = Branch::factory()->create();
    $this->cashier = msgStaff(RoleEnum::SalesStaff->value, [$this->branch]);
    $this->employee = Employee::where('user_id', $this->cashier->id)->first();
});

/**
 * An order stuck in one status since `$minutesAgo`, put there by `$employee`.
 *
 * Backdates the history row the OrderObserver already wrote rather than adding a
 * second one. The detector measures from the MOST RECENT entry for the status —
 * correct, because an order can return to a status it has been in before — so an
 * extra row stamped `now()` would make every order look freshly arrived.
 */
function msgStalledOrder($branch, $employee, string $status, int $minutesAgo): Order
{
    $order = Order::factory()->create([
        'branch_id' => $branch->id,
        'status' => $status,
        'assigned_employee_id' => $employee->id,
        'created_at' => now()->subMinutes($minutesAgo),
    ]);

    OrderStatusHistory::where('order_id', $order->id)->update([
        'status' => $status,
        'changed_by_id' => $employee->user_id,
        // customer|employee|system — a coarse classification, not a morph class.
        // `changed_by_id` points at users.id either way.
        'changed_by_type' => 'employee',
        'changed_at' => now()->subMinutes($minutesAgo),
    ]);

    return $order->fresh();
}

it('messages the person who left an order sitting', function () {
    $order = msgStalledOrder($this->branch, $this->employee, 'received', 45);
    $rule = StaffMessageRule::factory()->live()->create();

    app(StaffRuleEvaluator::class)->run($rule);

    $message = StaffMessage::where('rule_id', $rule->id)->first();

    expect($message)->not->toBeNull()
        ->and($message->recipients()->pluck('user_id')->all())->toBe([$this->cashier->id])
        ->and($message->body)->toContain($order->order_number)
        // Automatic messages carry no sender, so the UI can say so rather than
        // implying a person sat down and wrote it.
        ->and($message->sender_user_id)->toBeNull();
});

it('leaves an order alone until it has actually been sitting too long', function () {
    msgStalledOrder($this->branch, $this->employee, 'received', 5);
    $rule = StaffMessageRule::factory()->live()->create(['conditions' => ['status' => 'received', 'minutes' => 15]]);

    app(StaffRuleEvaluator::class)->run($rule);

    expect(StaffMessage::where('rule_id', $rule->id)->count())->toBe(0);
});

it('uses first names only', function () {
    $this->cashier->update(['name' => 'Kwame Mensah']);
    msgStalledOrder($this->branch, $this->employee, 'received', 45);

    $rule = StaffMessageRule::factory()->live()->create([
        'body_template' => 'Hi {first_name}, please check {order_number}.',
    ]);

    app(StaffRuleEvaluator::class)->run($rule);

    $body = StaffMessage::where('rule_id', $rule->id)->value('body');

    expect($body)->toContain('Kwame')->and($body)->not->toContain('Mensah');
});

it('renders an unknown merge field as nothing rather than as braces', function () {
    msgStalledOrder($this->branch, $this->employee, 'received', 45);

    $rule = StaffMessageRule::factory()->live()->create([
        'body_template' => 'Check {order_number} {not_a_real_field} now.',
    ]);

    app(StaffRuleEvaluator::class)->run($rule);

    expect(StaffMessage::where('rule_id', $rule->id)->value('body'))
        ->not->toContain('{not_a_real_field}');
});

it('does not nag the same person about the same order twice', function () {
    msgStalledOrder($this->branch, $this->employee, 'received', 45);
    $rule = StaffMessageRule::factory()->live()->create(['cooldown_minutes' => 120]);

    $evaluator = app(StaffRuleEvaluator::class);
    $evaluator->run($rule);
    $evaluator->run($rule);

    expect(StaffMessage::where('rule_id', $rule->id)->count())->toBe(1)
        ->and(StaffMessageRuleFire::where('suppressed_reason', StaffMessageSuppression::Cooldown->value)->count())
        ->toBe(1);
});

it('records the fire but sends nothing while the rule is switched off', function () {
    msgStalledOrder($this->branch, $this->employee, 'received', 45);
    $rule = StaffMessageRule::factory()->create(); // is_active false

    app(StaffRuleEvaluator::class)->run($rule);

    expect(StaffMessage::where('rule_id', $rule->id)->count())->toBe(0)
        ->and(StaffMessageRuleFire::where('rule_id', $rule->id)
            ->where('suppressed_reason', StaffMessageSuppression::RuleInactive->value)->count())
        ->toBe(1);
});

it('records the fire but sends nothing while the kill switch is off', function () {
    config()->set('staff_messaging.automation_enabled', false);

    msgStalledOrder($this->branch, $this->employee, 'received', 45);
    $rule = StaffMessageRule::factory()->live()->create();

    app(StaffRuleEvaluator::class)->run($rule);

    // Still evaluated, still recorded — this is how a rule earns trust before
    // anybody lets it near a person.
    expect(StaffMessage::where('rule_id', $rule->id)->count())->toBe(0)
        ->and(StaffMessageRuleFire::where('suppressed_reason', StaffMessageSuppression::FeatureOff->value)->count())
        ->toBe(1);
});

it('holds back once one person has had their fill for the hour', function () {
    config()->set('staff_messaging.recipient_hourly_cap', 2);

    // Three separate orders, so the per-subject cooldown is not what stops it.
    foreach ([45, 46, 47] as $minutes) {
        msgStalledOrder($this->branch, $this->employee, 'received', $minutes);
    }

    $rule = StaffMessageRule::factory()->live()->create();
    app(StaffRuleEvaluator::class)->run($rule);

    expect(StaffMessage::where('rule_id', $rule->id)->count())->toBe(2)
        ->and(StaffMessageRuleFire::where('suppressed_reason', StaffMessageSuppression::RecipientCapped->value)->count())
        ->toBe(1);
});

it('stops holding once the order moves', function () {
    // Exercised against the detector directly. In one synchronous run detection
    // and send are milliseconds apart, so the re-check almost never bites there
    // — the gap it exists for is the queued path, where an order really can move
    // while the job waits. Testing it through the evaluator would prove nothing.
    $order = msgStalledOrder($this->branch, $this->employee, 'received', 45);
    $rule = StaffMessageRule::factory()->live()->create();

    $detector = app(\App\Services\StaffMessaging\DetectorRegistry::class)->for($rule->event);
    $match = $detector->detect($rule, now()->subDays(30))->first();

    expect($detector->stillHolds($rule, $match))->toBeTrue();

    $order->update(['status' => 'preparing']);

    expect($detector->stillHolds($rule, $match))->toBeFalse();
});

it('records that a rule found nobody to tell', function () {
    // A website order nobody has touched: no actor, so a rule targeting the
    // actor legitimately reaches nobody.
    $order = Order::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => 'received',
        'assigned_employee_id' => null,
        'created_at' => now()->subMinutes(45),
    ]);

    // Backdate the row the observer wrote, or the order reads as freshly arrived.
    OrderStatusHistory::where('order_id', $order->id)
        ->update(['changed_at' => now()->subMinutes(45)]);

    $rule = StaffMessageRule::factory()->live()->create();
    app(StaffRuleEvaluator::class)->run($rule);

    expect(StaffMessageRuleFire::where('suppressed_reason', StaffMessageSuppression::NoRecipients->value)->count())
        ->toBe(1);
});

it('lets the higher-priority rule claim an order when two match', function () {
    msgStalledOrder($this->branch, $this->employee, 'received', 45);

    $loud = StaffMessageRule::factory()->live()->create(['name' => 'Loud', 'priority' => 100]);
    StaffMessageRule::factory()->live()->create(['name' => 'Quiet', 'priority' => 10]);

    app(StaffRuleEvaluator::class)->run();

    expect(StaffMessage::count())->toBe(1)
        ->and(StaffMessage::first()->rule_id)->toBe($loud->id)
        ->and(StaffMessageRuleFire::where('suppressed_reason', StaffMessageSuppression::LowerPriority->value)->count())
        ->toBe(1);
});

it('catches a stalled order the branch manager should hear about', function () {
    msgStalledOrder($this->branch, $this->employee, 'received', 90);
    $manager = msgStaff(RoleEnum::Manager->value, [$this->branch]);

    $rule = StaffMessageRule::factory()->live()->create([
        'conditions' => ['status' => 'received', 'minutes' => 60],
        'target' => ['types' => [StaffMessageTarget::BranchManagers->value]],
    ]);

    app(StaffRuleEvaluator::class)->run($rule);

    expect(StaffMessage::where('rule_id', $rule->id)->first()->recipients()->pluck('user_id')->all())
        ->toBe([$manager->id]);
});

it('spots a junk phone number on a staff-taken order', function () {
    Order::factory()->create([
        'branch_id' => $this->branch->id,
        'order_source' => 'pos',
        'contact_phone' => '0244444444',
        'assigned_employee_id' => $this->employee->id,
        'created_at' => now()->subMinutes(5),
    ]);

    $rule = StaffMessageRule::factory()->live()->create([
        'event' => StaffMessageEvent::SuspiciousCustomerPhone->value,
        'conditions' => [],
        'body_template' => 'The number on {order_number} is {reason}.',
    ]);

    app(StaffRuleEvaluator::class)->run($rule);

    expect(StaffMessage::where('rule_id', $rule->id)->value('body'))->toContain('repeated');
});

it('never blames staff for a number the customer typed themselves', function () {
    Order::factory()->create([
        'branch_id' => $this->branch->id,
        // A website order — the customer entered this, not a member of staff.
        'order_source' => 'online',
        'contact_phone' => '0244444444',
        'assigned_employee_id' => $this->employee->id,
        'created_at' => now()->subMinutes(5),
    ]);

    $rule = StaffMessageRule::factory()->live()->create([
        'event' => StaffMessageEvent::SuspiciousCustomerPhone->value,
        'conditions' => [],
    ]);

    app(StaffRuleEvaluator::class)->run($rule);

    expect(StaffMessage::where('rule_id', $rule->id)->count())->toBe(0);
});

it('dry-runs without writing or sending anything', function () {
    msgStalledOrder($this->branch, $this->employee, 'received', 45);
    $rule = StaffMessageRule::factory()->create(); // switched off

    $result = app(StaffRuleDryRun::class)->run($rule, 30);

    expect($result['matched'])->toBe(1)
        ->and($result['would_send'])->toBe(1)
        ->and($result['busiest_recipient'])->toBe(1)
        ->and($result['samples'][0]['body'])->toBeString()
        // Nothing was written. That is the whole contract.
        ->and(StaffMessage::count())->toBe(0)
        ->and(StaffMessageRuleFire::count())->toBe(0);
});

it('reports the dry run as a ceiling, not a promise', function () {
    $rule = StaffMessageRule::factory()->create();

    expect(app(StaffRuleDryRun::class)->run($rule, 30)['is_ceiling'])->toBeTrue();
});

it('does not rewrite the same held-back observation on every run', function () {
    // The state this ships in: kill switch off, scheduler every five minutes,
    // nothing ever leaving the match set by being sent. Without deduplication
    // this is ~288 identical rows per rule per order per day.
    config()->set('staff_messaging.automation_enabled', false);

    msgStalledOrder($this->branch, $this->employee, 'received', 45);
    $rule = StaffMessageRule::factory()->live()->create();

    $evaluator = app(StaffRuleEvaluator::class);
    $evaluator->run($rule);
    $evaluator->run($rule);
    $evaluator->run($rule);

    expect(StaffMessageRuleFire::where('rule_id', $rule->id)->count())->toBe(1);
});

it('still records a held-back observation once the window has passed', function () {
    config()->set('staff_messaging.automation_enabled', false);

    msgStalledOrder($this->branch, $this->employee, 'received', 45);
    $rule = StaffMessageRule::factory()->live()->create(['cooldown_minutes' => 60]);

    $evaluator = app(StaffRuleEvaluator::class);
    $evaluator->run($rule);

    $this->travel(90)->minutes();
    $evaluator->run($rule);

    expect(StaffMessageRuleFire::where('rule_id', $rule->id)->count())->toBe(2);
});

it('does not switch a live rule off when the seeder is re-run', function () {
    // Re-running the seeder to correct a template must not quietly disable
    // every rule somebody had turned on.
    $this->seed(\Database\Seeders\StaffMessageRuleSeeder::class);

    $rule = StaffMessageRule::where('name', 'Food ready, nobody has collected it')->first();
    $rule->update(['is_active' => true]);

    $this->seed(\Database\Seeders\StaffMessageRuleSeeder::class);

    expect($rule->fresh()->is_active)->toBeTrue();
});

it('seeds a brand-new rule switched off', function () {
    $this->seed(\Database\Seeders\StaffMessageRuleSeeder::class);

    expect(StaffMessageRule::where('is_active', true)->count())->toBe(0);
});
