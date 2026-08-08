<?php

use App\Models\SmsDeliveryAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    User::query()->forceDelete();

    SpatieRole::findOrCreate('admin', 'api')
        ->givePermissionTo(SpatiePermission::findOrCreate('manage_campaigns', 'api'));
    SpatieRole::findOrCreate('cashier', 'api')
        ->givePermissionTo(SpatiePermission::findOrCreate('view_customers', 'api'));

    config([
        'services.hubtel.client_id' => 'test-id',
        'services.hubtel.client_secret' => 'test-secret',
        'campaigns.estimated_rate_per_segment' => 0.0243,
    ]);
});

/** Idempotent — a test that sends twice needs the same admin, not two of them. */
function directAdmin(): User
{
    $existing = User::where('phone', '+233200000021')->first();

    if ($existing) {
        return $existing;
    }

    $user = User::factory()->create(['phone' => '+233200000021']);
    $user->assignRole('admin');

    return $user;
}

function fakeHubtelAccepts(): void
{
    Http::fake(['*' => Http::response(['status' => 0, 'messageId' => 'abc-123'], 201)]);
}

it('sends one text to one number', function () {
    fakeHubtelAccepts();

    $this->actingAs(directAdmin(), 'sanctum')
        ->postJson('/v1/admin/messages/send', [
            'phone' => '0241234567',
            'message' => 'Your order is ready.',
        ])
        ->assertOk()
        ->assertJsonPath('data.phone', '+233241234567');

    Http::assertSent(fn ($request) => $request['To'] === '233241234567');
});

it('accepts a number in whatever shape it was pasted', function (string $typed) {
    fakeHubtelAccepts();

    $this->actingAs(directAdmin(), 'sanctum')
        ->postJson('/v1/admin/messages/send', ['phone' => $typed, 'message' => 'Hello.'])
        ->assertOk()
        ->assertJsonPath('data.phone', '+233241234567');
})->with([
    'local' => '0241234567',
    'international' => '+233241234567',
    'no plus' => '233241234567',
    'spaced' => '024 123 4567',
    // Excel eats the leading zero; a number copied out of a sheet still works.
    'zero eaten' => '241234567',
]);

it('refuses a number that is not a Ghana mobile before spending anything', function () {
    Http::fake();

    $this->actingAs(directAdmin(), 'sanctum')
        ->postJson('/v1/admin/messages/send', ['phone' => '12345', 'message' => 'Hello.'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'Ghana mobile'));

    Http::assertNothingSent();
});

it('sends outside the campaign window, because a single reply is not a blast', function () {
    fakeHubtelAccepts();

    config(['campaigns.send_window.enabled' => true, 'campaigns.send_window.start_hour' => 8]);
    // 03:00, which would refuse a campaign outright.
    $this->travelTo(now()->setTime(3, 0));

    $this->actingAs(directAdmin(), 'sanctum')
        ->postJson('/v1/admin/messages/send', ['phone' => '0241234567', 'message' => 'Sorry about tonight.'])
        ->assertOk();
});

it('goes to the number that was typed even when seed mode is on', function () {
    /*
     * Seed mode redirects campaign recipients to a fixed test list. Applied
     * here it would deliver the message to somebody other than the person it
     * was addressed to, which is the one outcome this endpoint must never
     * produce.
     */
    fakeHubtelAccepts();

    config([
        'campaigns.seed_mode' => true,
        'campaigns.seed_list' => ['+233209999999'],
    ]);

    $this->actingAs(directAdmin(), 'sanctum')
        ->postJson('/v1/admin/messages/send', ['phone' => '0241234567', 'message' => 'Hello.'])
        ->assertOk();

    Http::assertSent(fn ($request) => $request['To'] === '233241234567');
});

it('records the attempt so a failure still reaches SMS health', function () {
    fakeHubtelAccepts();

    $this->actingAs(directAdmin(), 'sanctum')
        ->postJson('/v1/admin/messages/send', ['phone' => '0241234567', 'message' => 'Hello.'])
        ->assertOk();

    $attempt = SmsDeliveryAttempt::first();

    expect($attempt)->not->toBeNull()
        ->and($attempt->recipient)->toBe('233241234567')
        // Not a campaign, so it counts toward the health verdict like any other
        // transactional message.
        ->and($attempt->is_campaign)->toBeFalse();
});

it('logs who sent what to whom', function () {
    fakeHubtelAccepts();

    $this->actingAs(directAdmin(), 'sanctum')
        ->postJson('/v1/admin/messages/send', ['phone' => '0241234567', 'message' => 'Your refund is on its way.'])
        ->assertOk();

    $entry = \App\Models\ActivityLog::where('event', 'direct_message_sent')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties['phone'])->toBe('+233241234567')
        ->and($entry->properties['message'])->toBe('Your refund is on its way.');
});

it('prices a single text the same way the campaign composer does', function () {
    $this->actingAs(directAdmin(), 'sanctum')
        ->postJson('/v1/admin/messages/measure', ['message' => 'Plain text.'])
        ->assertOk()
        ->assertJsonPath('data.segments', 1)
        ->assertJsonPath('data.estimated_cost', 0.0243);

    // A curly quote drops the limit from 160 to 70 and can cost another text.
    $this->actingAs(directAdmin(), 'sanctum')
        ->postJson('/v1/admin/messages/measure', ['message' => 'Here’s one.'])
        ->assertOk()
        ->assertJsonPath('data.encoding', 'UCS_2');
});

it('keeps single sends behind manage_campaigns', function () {
    $cashier = User::factory()->create(['phone' => '+233200000022']);
    $cashier->assignRole('cashier');

    $this->actingAs($cashier, 'sanctum')
        ->postJson('/v1/admin/messages/send', ['phone' => '0241234567', 'message' => 'Hello.'])
        ->assertForbidden();
});
