<?php

use App\Enums\AutomationEvent;
use App\Models\AutomationFire;
use App\Models\AutomationRule;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();

    Order::query()->forceDelete();
    Customer::query()->forceDelete();
    User::query()->forceDelete();

    SpatieRole::findOrCreate('admin', 'api')
        ->givePermissionTo(SpatiePermission::findOrCreate('manage_campaigns', 'api'));
    SpatieRole::findOrCreate('cashier', 'api')
        ->givePermissionTo(SpatiePermission::findOrCreate('view_customers', 'api'));

    config(['automation.enabled' => false, 'automation.cooldown_days' => 3]);
});

function automationAdmin(): User
{
    $existing = User::where('phone', '+233200000051')->first();

    if ($existing) {
        return $existing;
    }

    $user = User::factory()->create(['phone' => '+233200000051']);
    $user->assignRole('admin');

    return $user;
}

// ─── Writing a rule ──────────────────────────────────────────────────────────

it('saves a new rule switched off, whatever was posted', function () {
    // A rule written this morning must not start texting people because
    // somebody hit save with a checkbox in the wrong state.
    $this->actingAs(automationAdmin(), 'sanctum')
        ->postJson('/v1/admin/automations', [
            'name' => 'Welcome',
            'event' => AutomationEvent::FirstOrder->value,
            'message' => 'Hi {name}, how was it?',
            'is_active' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.is_active', false);
});

it('refuses an event whose own setting is missing', function () {
    // "Their Nth order" with no N is a rule that matches nothing — or, read
    // differently by a future change, everything.
    $this->actingAs(automationAdmin(), 'sanctum')
        ->postJson('/v1/admin/automations', [
            'name' => 'Loyalty',
            'event' => AutomationEvent::NthOrder->value,
            'message' => 'Thanks for ordering again.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['event_config.order_number']);
});

it('accepts an event setting when it is given', function () {
    $this->actingAs(automationAdmin(), 'sanctum')
        ->postJson('/v1/admin/automations', [
            'name' => 'Loyalty',
            'event' => AutomationEvent::NthOrder->value,
            'event_config' => ['order_number' => 10],
            'message' => 'Ten orders. Thank you.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.event_config.order_number', 10);
});

it('does not change whether a rule is live when it is edited', function () {
    $rule = AutomationRule::factory()->create(['is_active' => true, 'created_by_user_id' => automationAdmin()->id]);

    $this->actingAs(automationAdmin(), 'sanctum')
        ->patchJson("/v1/admin/automations/{$rule->id}", [
            'message' => 'Changed my mind about the wording.',
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});

// ─── Switching on ────────────────────────────────────────────────────────────

it('switches a rule on through its own endpoint', function () {
    $rule = AutomationRule::factory()->create(['is_active' => false, 'created_by_user_id' => automationAdmin()->id]);

    $this->actingAs(automationAdmin(), 'sanctum')
        ->postJson("/v1/admin/automations/{$rule->id}/toggle", ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});

it('says plainly when a rule is on but the feature is not', function () {
    // Otherwise somebody watches a live rule do nothing and concludes it is
    // broken. Two switches; the screen has to admit it.
    $rule = AutomationRule::factory()->create(['is_active' => false, 'created_by_user_id' => automationAdmin()->id]);

    $this->actingAs(automationAdmin(), 'sanctum')
        ->postJson("/v1/admin/automations/{$rule->id}/toggle", ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'switched off globally'));
});

it('tells the list whether automation is on at all', function () {
    $this->actingAs(automationAdmin(), 'sanctum')
        ->getJson('/v1/admin/automations')
        ->assertOk()
        ->assertJsonPath('data.automation_enabled', false)
        ->assertJsonPath('data.cooldown_days', 3);
});

// ─── Reporting ───────────────────────────────────────────────────────────────

it('counts matched separately from sent, because the gap is the guardrails working', function () {
    $rule = AutomationRule::factory()->create(['created_by_user_id' => automationAdmin()->id]);

    AutomationFire::create(['automation_rule_id' => $rule->id, 'phone' => '+233241111111', 'fired_at' => now(), 'sent_at' => now()]);
    AutomationFire::create(['automation_rule_id' => $rule->id, 'phone' => '+233242222222', 'fired_at' => now(), 'suppressed_reason' => AutomationFire::COOLDOWN]);
    AutomationFire::create(['automation_rule_id' => $rule->id, 'phone' => '+233243333333', 'fired_at' => now(), 'suppressed_reason' => AutomationFire::COOLDOWN]);

    $this->actingAs(automationAdmin(), 'sanctum')
        ->getJson("/v1/admin/automations/{$rule->id}")
        ->assertOk()
        // 3 matched, 1 sent. Showing only the 1 would read as a rule that
        // barely fires rather than a cooldown doing its job.
        ->assertJsonPath('data.matched_count', 3)
        ->assertJsonPath('data.sent_count', 1)
        ->assertJsonPath('data.suppression_breakdown.cooldown', 2);
});

it('reports no response rate until something has been sent', function () {
    // 0% and "nothing has gone out yet" are different facts.
    $rule = AutomationRule::factory()->create(['created_by_user_id' => automationAdmin()->id]);

    $this->actingAs(automationAdmin(), 'sanctum')
        ->getJson("/v1/admin/automations/{$rule->id}")
        ->assertOk()
        ->assertJsonPath('data.response_rate', null);
});

it('credits an answer back to the rule that asked for it', function () {
    // Without this, response rate is always zero and there is no way to learn
    // which asks work.
    $rule = AutomationRule::factory()->create(['created_by_user_id' => automationAdmin()->id]);
    $order = Order::factory()->create(['customer_id' => null, 'status' => 'completed']);

    $fire = AutomationFire::create([
        'automation_rule_id' => $rule->id, 'order_id' => $order->id,
        'phone' => '+233241111111', 'fired_at' => now(), 'sent_at' => now(),
    ]);

    $feedback = OrderFeedback::create([
        'order_id' => $order->id,
        'token' => 'tok123abc',
        'expires_at' => now()->addDays(7),
    ]);

    $this->postJson("/v1/order-feedback/{$feedback->token}", ['rating_overall' => 5])
        ->assertOk();

    expect($fire->fresh()->order_feedback_id)->toBe($feedback->id);

    $this->actingAs(automationAdmin(), 'sanctum')
        ->getJson("/v1/admin/automations/{$rule->id}")
        // round() gives a float; json_encode writes a whole number as an int.
        ->assertJsonPath('data.response_rate', 100);
});

// ─── Dry run ─────────────────────────────────────────────────────────────────

it('serves the dry run without sending or recording anything', function () {
    $rule = AutomationRule::factory()->create(['created_by_user_id' => automationAdmin()->id]);

    Order::withoutEvents(fn () => Order::factory()->create([
        'customer_id' => null, 'contact_phone' => '+233241111111',
        'status' => 'completed', 'created_at' => now()->subDays(3),
    ]));

    $this->actingAs(automationAdmin(), 'sanctum')
        ->getJson("/v1/admin/automations/{$rule->id}/dry-run?days=30")
        ->assertOk()
        ->assertJsonPath('data.would_send', 1)
        ->assertJsonStructure(['data' => ['matched', 'would_send', 'estimated_cost', 'busiest_recipient', 'sample']]);

    expect(AutomationFire::count())->toBe(0);
});

// ─── Options and gating ──────────────────────────────────────────────────────

it('serves the events and merge fields the builder needs', function () {
    $this->actingAs(automationAdmin(), 'sanctum')
        ->getJson('/v1/admin/automations/options')
        ->assertOk()
        ->assertJsonPath('data.events.0.value', AutomationEvent::FirstOrder->value)
        ->assertJsonFragment(['field' => '{name}']);
});

it('keeps automation behind manage_campaigns', function () {
    $cashier = User::factory()->create(['phone' => '+233200000052']);
    $cashier->assignRole('cashier');

    $this->actingAs($cashier, 'sanctum')->getJson('/v1/admin/automations')->assertForbidden();
    $this->actingAs($cashier, 'sanctum')
        ->postJson('/v1/admin/automations', ['name' => 'x'])
        ->assertForbidden();
});
