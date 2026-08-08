<?php

use App\Enums\AutomationEvent;
use App\Jobs\SendAutomationMessage;
use App\Models\AutomationFire;
use App\Models\AutomationRule;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShortLink;
use App\Models\SmsDeliveryAttempt;
use App\Models\User;
use App\Services\Automation\MessageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

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
        'services.hubtel.client_id' => 'test-id',
        'services.hubtel.client_secret' => 'test-secret',
    ]);

    Http::fake(['*' => Http::response(['status' => 0, 'messageId' => 'abc'], 201)]);
});

function sendRule(array $attributes = []): AutomationRule
{
    return AutomationRule::factory()->create([
        'event' => AutomationEvent::FirstOrder->value,
        'message' => 'Hi {name}, how was your first order?',
        'delay_minutes' => 180,
        ...$attributes,
    ]);
}

function finishOrder(string $phone, array $attributes = []): Order
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

// ─── Queueing ────────────────────────────────────────────────────────────────

it('queues the send for later rather than sending on the spot', function () {
    Bus::fake();
    sendRule(['delay_minutes' => 180]);

    finishOrder('+233241111111');

    // Asking how the food was while somebody is still eating it is worse than
    // not asking.
    Bus::assertDispatched(SendAutomationMessage::class, fn ($job) => $job->delay !== null);
});

it('queues nothing while the feature is off', function () {
    Bus::fake();
    config(['automation.enabled' => false]);
    sendRule();

    finishOrder('+233241111111');

    Bus::assertNotDispatched(SendAutomationMessage::class);
});

it('queues one send even when several rules match', function () {
    Bus::fake();
    sendRule(['priority' => 10]);
    AutomationRule::factory()->create([
        'event' => AutomationEvent::FirstOrderType->value,
        'priority' => 50,
    ]);

    finishOrder('+233241111111', ['order_type' => 'delivery']);

    Bus::assertDispatchedTimes(SendAutomationMessage::class, 1);
});

// ─── Sending ─────────────────────────────────────────────────────────────────

it('sends the message and marks the firing', function () {
    sendRule();
    finishOrder('+233241111111', ['contact_name' => 'Kwame Mensah']);

    $fire = AutomationFire::notSuppressed()->first();
    (new SendAutomationMessage($fire->id))->handle(
        app(\App\Services\Automation\AutomationGuard::class),
        app(MessageRenderer::class),
        app(\App\Services\HubtelSmsService::class),
    );

    expect($fire->fresh()->sent_at)->not->toBeNull();

    Http::assertSent(fn ($request) => $request['To'] === '233241111111'
        && str_contains($request['Content'], 'Hi Kwame,'));
});

it('counts toward SMS health like any other transactional message', function () {
    // The user's decision. A bad rule will drag the verdict and look like an
    // outage — the trade for noticing when automated messages stop arriving.
    sendRule();
    finishOrder('+233241111111');

    $fire = AutomationFire::notSuppressed()->first();
    (new SendAutomationMessage($fire->id))->handle(
        app(\App\Services\Automation\AutomationGuard::class),
        app(MessageRenderer::class),
        app(\App\Services\HubtelSmsService::class),
    );

    expect(SmsDeliveryAttempt::first()->is_campaign)->toBeFalse();
});

// ─── The guards, re-checked hours later ──────────────────────────────────────

it('does not ask how the food was when the order was cancelled in the meantime', function () {
    // Three hours is a long time. This is the worst thing the feature could do.
    sendRule();
    $order = finishOrder('+233241111111');

    $fire = AutomationFire::notSuppressed()->first();
    $order->update(['status' => 'cancelled']);

    (new SendAutomationMessage($fire->id))->handle(
        app(\App\Services\Automation\AutomationGuard::class),
        app(MessageRenderer::class),
        app(\App\Services\HubtelSmsService::class),
    );

    expect($fire->fresh()->sent_at)->toBeNull()
        ->and($fire->fresh()->suppressed_reason)->toBe(SendAutomationMessage::ORDER_CANCELLED);

    Http::assertNothingSent();
});

it('stops if the rule was switched off while it waited', function () {
    $rule = sendRule();
    finishOrder('+233241111111');

    $fire = AutomationFire::notSuppressed()->first();
    $rule->update(['is_active' => false]);

    (new SendAutomationMessage($fire->id))->handle(
        app(\App\Services\Automation\AutomationGuard::class),
        app(MessageRenderer::class),
        app(\App\Services\HubtelSmsService::class),
    );

    expect($fire->fresh()->suppressed_reason)->toBe(AutomationFire::FEATURE_OFF);
    Http::assertNothingSent();
});

it('stops if the kill switch went down while it waited', function () {
    sendRule();
    finishOrder('+233241111111');

    $fire = AutomationFire::notSuppressed()->first();
    config(['automation.enabled' => false]);

    (new SendAutomationMessage($fire->id))->handle(
        app(\App\Services\Automation\AutomationGuard::class),
        app(MessageRenderer::class),
        app(\App\Services\HubtelSmsService::class),
    );

    expect($fire->fresh()->sent_at)->toBeNull();
    Http::assertNothingSent();
});

it('does not treat its own firing as a reason to stay silent', function () {
    // The firing is inside its own cooldown window by definition. Without
    // excluding itself, nothing would ever send.
    sendRule();
    finishOrder('+233241111111');

    $fire = AutomationFire::notSuppressed()->first();
    (new SendAutomationMessage($fire->id))->handle(
        app(\App\Services\Automation\AutomationGuard::class),
        app(MessageRenderer::class),
        app(\App\Services\HubtelSmsService::class),
    );

    expect($fire->fresh()->sent_at)->not->toBeNull();
});

it('never sends the same firing twice', function () {
    sendRule();
    finishOrder('+233241111111');

    $fire = AutomationFire::notSuppressed()->first();

    foreach (range(1, 3) as $ignored) {
        (new SendAutomationMessage($fire->id))->handle(
            app(\App\Services\Automation\AutomationGuard::class),
            app(MessageRenderer::class),
            app(\App\Services\HubtelSmsService::class),
        );
    }

    expect(SmsDeliveryAttempt::count())->toBe(1);
});

// ─── Merge fields ────────────────────────────────────────────────────────────

it('fills the blanks, first names only', function () {
    $branch = Branch::factory()->create(['name' => 'Ashaiman']);
    $rule = sendRule(['message' => 'Hi {name}, how was {branch}? Order {order_number}.']);
    $order = finishOrder('+233241111111', [
        'contact_name' => 'Kwame Mensah Boateng',
        'branch_id' => $branch->id,
        'order_number' => 'CB123456',
    ]);

    // "Hi Kwame Mensah Boateng," reads like a summons.
    expect(app(MessageRenderer::class)->render($rule, $order))
        ->toBe('Hi Kwame, how was Ashaiman? Order CB123456.');
});

it('reads naturally when there is no name', function () {
    // orders.contact_name is NOT NULL, so the empty string is what "no name"
    // actually looks like — a counter order taken in a hurry. Without the
    // fallback this is the classic "Hi , how was it?".
    $rule = sendRule(['message' => 'Hi {name}, how was it?']);
    $order = finishOrder('+233241111111', ['contact_name' => '']);

    expect(app(MessageRenderer::class)->render($rule, $order))->toBe('Hi there, how was it?');
});

it('names the dish somebody actually just tried', function () {
    $item = MenuItem::factory()->create(['name' => 'Waakye']);
    $option = MenuItemOption::factory()->create([
        'menu_item_id' => $item->id, 'option_label' => 'Large', 'display_name' => null,
    ]);

    $rule = sendRule([
        'event' => AutomationEvent::TriedSomethingNew->value,
        'message' => 'How was the {dish}?',
    ]);

    $order = Order::factory()->create([
        'customer_id' => null, 'contact_phone' => '+233241111111', 'status' => 'completed',
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id, 'menu_item_id' => $item->id, 'menu_item_option_id' => $option->id,
    ]);

    expect(app(MessageRenderer::class)->render($rule, $order->fresh()))
        ->toBe('How was the Waakye Large?');
});

it('substitutes an attached link', function () {
    $link = ShortLink::factory()->create();
    $rule = sendRule(['message' => 'Tell us here: {link}', 'short_link_id' => $link->id]);
    $order = finishOrder('+233241111111');

    expect(app(MessageRenderer::class)->render($rule->fresh(), $order))
        ->toContain($link->smsUrl());
});

it('never shows a customer our template syntax', function () {
    $rule = sendRule(['message' => 'Hi {name}, {something_we_do_not_fill} thanks.']);
    $order = finishOrder('+233241111111', ['contact_name' => 'Ama']);

    expect(app(MessageRenderer::class)->render($rule, $order))
        ->toBe('Hi Ama, thanks.')
        ->not->toContain('{');
});
