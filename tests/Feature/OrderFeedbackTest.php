<?php

use App\Jobs\RequestOrderFeedback;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    SpatieRole::findOrCreate('admin', 'api')
        ->givePermissionTo(SpatiePermission::findOrCreate('manage_campaigns', 'api'));

    config([
        'order_feedback.enabled' => true,
        'order_feedback.delay_hours' => 3,
        'order_feedback.send_window.start_hour' => 8,
        'order_feedback.send_window.end_hour' => 19,
        'order_feedback.per_customer_daily_cap' => 1,
        'order_feedback.expires_after_days' => 7,
        'order_feedback.token_length' => 8,
        'short_links.base_url' => 'https://cedibites.com',
        'services.hubtel.client_id' => 'test-id',
        'services.hubtel.client_secret' => 'test-secret',
    ]);

    // Inside the window, on a weekday, unless a test says otherwise.
    test()->travelTo(now()->setDate(2026, 8, 5)->setTime(12, 0));

    Http::fake(['*' => Http::response(['messageId' => 'abc123', 'status' => 0, 'rate' => 0.05], 200)]);
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

function feedbackAdmin(): User
{
    $existing = User::where('phone', '+233200000009')->first();

    if ($existing) {
        return $existing;
    }

    $user = User::factory()->create(['phone' => '+233200000009']);
    $user->assignRole('admin');

    return $user;
}

function completedOrder(array $attributes = []): Order
{
    return Order::factory()->create([
        'customer_id' => null,
        'contact_phone' => '+233241111111',
        'contact_name' => 'Ama',
        'status' => 'completed',
        'order_source' => 'online',
        ...$attributes,
    ]);
}

function openFeedback(array $attributes = []): OrderFeedback
{
    return OrderFeedback::create([
        'order_id' => completedOrder()->id,
        'token' => 'K3mQ9xR2',
        'sent_at' => now(),
        'expires_at' => now()->addDays(7),
        ...$attributes,
    ]);
}

// ─── The kill switch ─────────────────────────────────────────────────────────

/*
 * Off by default, and off means nothing is dispatched at all — not dispatched
 * and then discarded. Every completed order becomes a message, so this must be
 * turned on deliberately rather than discovered.
 */
it('dispatches nothing while the kill switch is off', function () {
    Bus::fake();
    config(['order_feedback.enabled' => false]);

    $order = completedOrder(['status' => 'preparing']);
    $order->update(['status' => 'completed']);

    Bus::assertNotDispatched(RequestOrderFeedback::class);
});

it('asks a few hours after the order completes', function () {
    Bus::fake();

    $order = completedOrder(['status' => 'preparing']);
    $order->update(['status' => 'completed']);

    Bus::assertDispatched(RequestOrderFeedback::class, fn ($job) => $job->orderId === $order->id);
});

/* The job re-checks the switch, because hours pass between dispatch and run. */
it('sends nothing if the switch is turned off before the job runs', function () {
    $order = completedOrder();
    config(['order_feedback.enabled' => false]);

    (new RequestOrderFeedback($order->id))->handle(app(\App\Services\HubtelSmsService::class));

    expect(OrderFeedback::count())->toBe(0);
});

// ─── The guards ──────────────────────────────────────────────────────────────

describe('who gets asked', function () {
    it('asks a guest who left a phone number', function () {
        $order = completedOrder();

        (new RequestOrderFeedback($order->id))->handle(app(\App\Services\HubtelSmsService::class));

        $feedback = OrderFeedback::sole();
        expect($feedback->order_id)->toBe($order->id)
            ->and($feedback->sent_at)->not->toBeNull()
            ->and(strlen($feedback->token))->toBe(8);
    });

    /*
     * Manual entries are historical records typed in after the fact.
     * OrderObserver already keeps them out of every other notification, and
     * texting somebody about a meal from last month is worse than saying nothing.
     */
    it('never asks about a manual entry', function () {
        $order = completedOrder(['order_source' => 'manual_entry']);

        (new RequestOrderFeedback($order->id))->handle(app(\App\Services\HubtelSmsService::class));

        expect(OrderFeedback::count())->toBe(0);
    });

    it('never asks about a cancelled order', function () {
        $order = completedOrder(['status' => 'cancelled']);

        (new RequestOrderFeedback($order->id))->handle(app(\App\Services\HubtelSmsService::class));

        expect(OrderFeedback::count())->toBe(0);
    });

    /*
     * Somebody who buys lunch and dinner is a good customer, not somebody to
     * text twice in a day.
     */
    it('asks once a day however many times they order', function () {
        $lunch = completedOrder();
        $dinner = completedOrder();
        $sms = app(\App\Services\HubtelSmsService::class);

        (new RequestOrderFeedback($lunch->id))->handle($sms);
        (new RequestOrderFeedback($dinner->id))->handle($sms);

        expect(OrderFeedback::count())->toBe(1);
    });

    /*
     * The unique index on order_id is the guard. A retried queue job must not
     * text the same person twice about the same meal.
     */
    it('does not ask twice about the same order when the job is retried', function () {
        config(['order_feedback.per_customer_daily_cap' => 0]);

        $order = completedOrder();
        $sms = app(\App\Services\HubtelSmsService::class);

        (new RequestOrderFeedback($order->id))->handle($sms);
        (new RequestOrderFeedback($order->id))->handle($sms);

        expect(OrderFeedback::where('order_id', $order->id)->count())->toBe(1);
    });

    /*
     * A 9pm dinner order plus three hours is midnight. The right gap after a
     * late dinner is breakfast, so the request rolls forward rather than being
     * dropped — a dropped one is feedback we never collect.
     */
    it('rolls a late-night request forward to the morning instead of dropping it', function () {
        Bus::fake();
        $this->travelTo(now()->setTime(23, 30));

        $order = completedOrder();

        (new RequestOrderFeedback($order->id))->handle(app(\App\Services\HubtelSmsService::class));

        expect(OrderFeedback::count())->toBe(0);
        Bus::assertDispatched(RequestOrderFeedback::class);
    });
});

// ─── The public form ─────────────────────────────────────────────────────────

describe('the form', function () {
    /* The token identifies the order, so they never type an order number. */
    it('already knows what they ate and where from', function () {
        $feedback = openFeedback();

        $this->getJson("/v1/order-feedback/{$feedback->token}")
            ->assertSuccessful()
            ->assertJsonPath('data.already_submitted', false)
            ->assertJsonPath('data.order_number', $feedback->order->order_number);
    });

    it('needs no authentication', function () {
        openFeedback();

        $this->getJson('/v1/order-feedback/K3mQ9xR2')->assertSuccessful();
    });

    it('takes three ratings and a comment', function () {
        $feedback = openFeedback();

        $this->postJson("/v1/order-feedback/{$feedback->token}", [
            'rating_overall' => 4,
            'rating_food' => 5,
            'rating_service' => 3,
            'comment' => 'Jollof was great, rider was late.',
        ])->assertSuccessful();

        $fresh = $feedback->fresh();
        expect($fresh->rating_overall)->toBe(4)
            ->and($fresh->rating_food)->toBe(5)
            ->and($fresh->rating_service)->toBe(3)
            ->and($fresh->comment)->toBe('Jollof was great, rider was late.')
            ->and($fresh->submitted_at)->not->toBeNull();
    });

    /*
     * One tap is a complete answer. Demanding all three is how a response rate
     * dies — people answer this standing up, on a phone.
     */
    it('accepts the overall score on its own', function () {
        $feedback = openFeedback();

        $this->postJson("/v1/order-feedback/{$feedback->token}", ['rating_overall' => 5])
            ->assertSuccessful();

        expect($feedback->fresh()->rating_overall)->toBe(5);
    });

    it('needs at least the overall score', function () {
        $feedback = openFeedback();

        $this->postJson("/v1/order-feedback/{$feedback->token}", ['comment' => 'Nice'])
            ->assertJsonValidationErrors('rating_overall');
    });

    it('refuses a rating outside 1 to 5', function () {
        $feedback = openFeedback();

        $this->postJson("/v1/order-feedback/{$feedback->token}", ['rating_overall' => 9])
            ->assertJsonValidationErrors('rating_overall');
    });

    /*
     * The form is not for changing your mind. Letting a forwarded link overwrite
     * an answer would mean anybody holding the URL could rewrite what the
     * customer said.
     */
    it('takes exactly one answer', function () {
        $feedback = openFeedback();

        $this->postJson("/v1/order-feedback/{$feedback->token}", ['rating_overall' => 5])
            ->assertSuccessful();

        $this->postJson("/v1/order-feedback/{$feedback->token}", ['rating_overall' => 1])
            ->assertNotFound();

        expect($feedback->fresh()->rating_overall)->toBe(5);
    });

    /* Their own second tap should read as "thanks, we have it", not as a dead link. */
    it('tells somebody who taps their own link again that it is already in', function () {
        $feedback = openFeedback(['submitted_at' => now(), 'rating_overall' => 5]);

        $this->getJson("/v1/order-feedback/{$feedback->token}")
            ->assertSuccessful()
            ->assertJsonPath('data.already_submitted', true);
    });

    /* A forwarded message must not seed feedback about a meal weeks later. */
    it('closes an expired link', function () {
        $feedback = openFeedback(['expires_at' => now()->subDay()]);

        $this->getJson("/v1/order-feedback/{$feedback->token}")->assertNotFound();
        $this->postJson("/v1/order-feedback/{$feedback->token}", ['rating_overall' => 5])->assertNotFound();
    });

    /* Expired and never-existed answer alike, so this is not a token oracle. */
    it('answers an unknown token the same way as an expired one', function () {
        $this->getJson('/v1/order-feedback/NoSuchTk')->assertNotFound();
    });
});

// ─── The admin view ──────────────────────────────────────────────────────────

describe('what came back', function () {
    it('lists only feedback somebody actually answered', function () {
        openFeedback(['submitted_at' => now(), 'rating_overall' => 5]);
        OrderFeedback::create([
            'order_id' => completedOrder()->id,
            'token' => 'Unanswrd',
            'sent_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $data = $this->actingAs(feedbackAdmin(), 'sanctum')
            ->getJson('/v1/admin/customer-feedback')
            ->assertSuccessful()
            ->json('data.data');

        expect($data)->toHaveCount(1)
            ->and($data[0]['rating_overall'])->toBe(5);
    });

    /*
     * Response rate is answered over *sent*. A request that failed to go out has
     * sent_at null and is excluded — a message nobody received must not read as
     * a message nobody answered.
     */
    it('measures the response rate against what actually went out', function () {
        openFeedback(['submitted_at' => now(), 'rating_overall' => 4]);

        // Sent, unanswered.
        OrderFeedback::create([
            'order_id' => completedOrder()->id, 'token' => 'Sent0001',
            'sent_at' => now(), 'expires_at' => now()->addDays(7),
        ]);

        // Never went out — must not count against the rate.
        OrderFeedback::create([
            'order_id' => completedOrder()->id, 'token' => 'NeverSnt',
            'sent_at' => null, 'expires_at' => now()->addDays(7),
        ]);

        $summary = $this->actingAs(feedbackAdmin(), 'sanctum')
            ->getJson('/v1/admin/customer-feedback')
            ->json('data.summary');

        // toEqual, not toBe: JSON renders 50.0 as 50, so the decoded value is an int.
        expect($summary['sent'])->toBe(2)
            ->and($summary['answered'])->toBe(1)
            ->and($summary['response_rate'])->toEqual(50.0)
            ->and($summary['average_overall'])->toEqual(4.0);
    });

    it('filters to the unhappy ones', function () {
        openFeedback(['submitted_at' => now(), 'rating_overall' => 5]);
        OrderFeedback::create([
            'order_id' => completedOrder()->id, 'token' => 'Unhappy1',
            'sent_at' => now(), 'submitted_at' => now(), 'rating_overall' => 2,
            'expires_at' => now()->addDays(7),
        ]);

        $data = $this->actingAs(feedbackAdmin(), 'sanctum')
            ->getJson('/v1/admin/customer-feedback?unhappy_only=1')
            ->json('data.data');

        expect($data)->toHaveCount(1)
            ->and($data[0]['rating_overall'])->toBe(2);
    });

    /* Read to find out what went wrong, not to look people up. */
    it('carries no phone number or address', function () {
        openFeedback(['submitted_at' => now(), 'rating_overall' => 3]);

        $row = $this->actingAs(feedbackAdmin(), 'sanctum')
            ->getJson('/v1/admin/customer-feedback')
            ->json('data.data.0');

        expect(array_keys($row))->not->toContain('phone')
            ->and(array_keys($row))->not->toContain('contact_phone')
            ->and(json_encode($row))->not->toContain('+233241111111');
    });

    it('rejects unauthenticated requests', function () {
        $this->getJson('/v1/admin/customer-feedback')->assertUnauthorized();
    });
});

// ─── The message ─────────────────────────────────────────────────────────────

/*
 * The link has to fit. At 160 characters this is one billed segment; a longer
 * URL or an `https://` would make every request cost two.
 */
it('fits the request into a single billed text', function () {
    $feedback = openFeedback();

    $message = \App\Notifications\OrderFeedbackRequestNotification::message($feedback);

    expect($message)->toContain('cedibites.com/f/K3mQ9xR2')
        ->and($message)->not->toContain('https://')
        ->and(app(\App\Services\Campaigns\MessageMeter::class)->segments($message))->toBe(1);
});

/*
 * `orders.contact_phone` is NOT NULL, so the reachable version of "no phone" is
 * an empty string — an order taken over the counter with nothing typed in. The
 * fallback to the customer's own number then finds nothing either.
 */
it('never asks when there is no number to ask on', function () {
    $user = User::factory()->create(['phone' => null]);
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $order = completedOrder(['customer_id' => $customer->id, 'contact_phone' => '']);

    (new RequestOrderFeedback($order->id))->handle(app(\App\Services\HubtelSmsService::class));

    expect(OrderFeedback::count())->toBe(0);
});
