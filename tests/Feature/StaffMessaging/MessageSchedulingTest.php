<?php

use App\Enums\Role as RoleEnum;
use App\Enums\StaffMessageKind;
use App\Enums\StaffMessageTrigger;
use App\Events\StaffMessageEvent;
use App\Models\Branch;
use App\Models\StaffMessage;
use App\Models\StaffMessageRecipient;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| When a message may appear, and proof that it did
|--------------------------------------------------------------------------
|
| Two halves of one question. `expires_at` already said when a message stops
| being live and nothing said when it starts, so every send was immediate.
|
| The other half is evidence. `delivered_at` is stamped by the dispatcher, so it
| records that a receipt was written, not that anything reached a screen. On a
| release nobody opens from the bell that left `acknowledged_at` as the only
| proof, and a walkthrough that appeared and was walked away from looked exactly
| like one that never appeared.
|
| The floor is enforced on the server, in the `live` scope, so a held message is
| invisible to the inbox, the bell count and the SMS escalation together. The
| trigger is the client's job, because every case turns on something only a
| browser knows.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    Notification::fake();
    Event::fake([StaffMessageEvent::class]);

    $this->branch = Branch::factory()->create();
    $this->admin = msgSender(RoleEnum::Admin->value);
});

function schedulingPayload(array $override = []): array
{
    return array_merge([
        'kind' => StaffMessageKind::Release->value,
        'release_key' => 'scheduling-'.uniqid(),
        'subject' => 'Online orders now come to the till',
        'body' => 'Two things changed.',
        'audience' => ['roles' => [RoleEnum::SalesStaff->value]],
        'requires_acknowledgement' => true,
        'steps' => [
            ['title' => 'Online orders now come to your till', 'body' => 'They arrive with a sound.'],
        ],
    ], $override);
}

// ─── The floor ────────────────────────────────────────────────────────────────

it('keeps a message held for later out of the pending set', function () {
    $cashier = msgStaff(RoleEnum::SalesStaff->value, [$this->branch]);

    $this->actingAs($this->admin)
        ->postJson('/v1/admin/messages', schedulingPayload([
            'visible_from' => now()->addDay()->toIso8601String(),
        ]))
        ->assertSuccessful();

    // The receipts exist. The send happened; only the appearance is deferred.
    expect(StaffMessageRecipient::where('user_id', $cashier->id)->count())->toBe(1);

    $summary = $this->actingAs($cashier)->getJson('/v1/messages/inbox/summary')->assertSuccessful();

    expect($summary->json('data.pending'))->toBeEmpty()
        ->and($summary->json('data.unread'))->toBe(0);
});

it('lets the message through once its time has come', function () {
    $cashier = msgStaff(RoleEnum::SalesStaff->value, [$this->branch]);

    $this->actingAs($this->admin)
        ->postJson('/v1/admin/messages', schedulingPayload([
            'visible_from' => now()->addHours(2)->toIso8601String(),
        ]))
        ->assertSuccessful();

    $this->travelTo(now()->addHours(3));

    $summary = $this->actingAs($cashier)->getJson('/v1/messages/inbox/summary')->assertSuccessful();

    expect($summary->json('data.pending'))->toHaveCount(1);
});

it('refuses a window that closes before it opens', function () {
    msgStaff(RoleEnum::SalesStaff->value, [$this->branch]);

    $this->actingAs($this->admin)
        ->postJson('/v1/admin/messages', schedulingPayload([
            'visible_from' => now()->addDays(3)->toIso8601String(),
            'expires_at' => now()->addDay()->toIso8601String(),
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('visible_from');
});

// ─── The trigger ──────────────────────────────────────────────────────────────

it('defaults to showing right away so existing sends do not change', function () {
    msgStaff(RoleEnum::SalesStaff->value, [$this->branch]);

    $this->actingAs($this->admin)->postJson('/v1/admin/messages', schedulingPayload())->assertSuccessful();

    expect(StaffMessage::latest('id')->first()->display_trigger)->toBe(StaffMessageTrigger::Immediate);
});

it('hands the trigger to the client, which is the only thing that can obey it', function () {
    $cashier = msgStaff(RoleEnum::SalesStaff->value, [$this->branch]);

    $this->actingAs($this->admin)
        ->postJson('/v1/admin/messages', schedulingPayload([
            'display_trigger' => StaffMessageTrigger::WindowActive->value,
        ]))
        ->assertSuccessful();

    $summary = $this->actingAs($cashier)->getJson('/v1/messages/inbox/summary')->assertSuccessful();

    expect($summary->json('data.pending.0.display_trigger'))->toBe('window_active');
});

it('rejects a trigger that is not one of the three', function () {
    msgStaff(RoleEnum::SalesStaff->value, [$this->branch]);

    $this->actingAs($this->admin)
        ->postJson('/v1/admin/messages', schedulingPayload(['display_trigger' => 'whenever']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('display_trigger');
});

// ─── The evidence ─────────────────────────────────────────────────────────────

it('records the first time it took somebody screen, and every time after', function () {
    $cashier = msgStaff(RoleEnum::SalesStaff->value, [$this->branch]);

    $this->actingAs($this->admin)->postJson('/v1/admin/messages', schedulingPayload())->assertSuccessful();

    $receipt = StaffMessageRecipient::where('user_id', $cashier->id)->firstOrFail();

    expect($receipt->shown_at)->toBeNull()
        ->and($receipt->shown_count)->toBe(0);

    $this->actingAs($cashier)->postJson("/v1/messages/inbox/{$receipt->id}/shown")->assertSuccessful();

    $first = $receipt->fresh();
    expect($first->shown_at)->not->toBeNull()
        ->and($first->shown_count)->toBe(1);

    // A release keeps interrupting until it is acknowledged, so the second
    // appearance must move `last_shown_at` without rewriting the first.
    $this->travelTo(now()->addMinutes(30));
    $this->actingAs($cashier)->postJson("/v1/messages/inbox/{$receipt->id}/shown")->assertSuccessful();

    $second = $receipt->fresh();
    expect($second->shown_at->timestamp)->toBe($first->shown_at->timestamp)
        ->and($second->last_shown_at->timestamp)->toBeGreaterThan($first->shown_at->timestamp)
        ->and($second->shown_count)->toBe(2);
});

it('separates being shown from being acknowledged', function () {
    $cashier = msgStaff(RoleEnum::SalesStaff->value, [$this->branch]);

    $this->actingAs($this->admin)->postJson('/v1/admin/messages', schedulingPayload())->assertSuccessful();

    $receipt = StaffMessageRecipient::where('user_id', $cashier->id)->firstOrFail();
    $this->actingAs($cashier)->postJson("/v1/messages/inbox/{$receipt->id}/shown")->assertSuccessful();

    // Seen, and walked away from. This is the state that was previously
    // indistinguishable from never having appeared at all.
    expect($receipt->fresh()->acknowledged_at)->toBeNull();

    $stats = StaffMessage::latest('id')->first()->deliveryStats();
    expect($stats['shown'])->toBe(1)
        ->and($stats['acknowledged'])->toBe(0);
});

it('will not let one person report an appearance on another person receipt', function () {
    $cashier = msgStaff(RoleEnum::SalesStaff->value, [$this->branch]);
    $other = msgStaff(RoleEnum::SalesStaff->value, [$this->branch]);

    $this->actingAs($this->admin)->postJson('/v1/admin/messages', schedulingPayload())->assertSuccessful();

    $receipt = StaffMessageRecipient::where('user_id', $cashier->id)->firstOrFail();

    $this->actingAs($other)->postJson("/v1/messages/inbox/{$receipt->id}/shown")->assertNotFound();

    expect($receipt->fresh()->shown_at)->toBeNull();
});
