<?php

use App\Enums\Role as RoleEnum;
use App\Enums\StaffMessageKind;
use App\Events\StaffMessageEvent;
use App\Models\Branch;
use App\Models\StaffMessage;
use App\Models\StaffMessageRecipient;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    Notification::fake();
    Event::fake([StaffMessageEvent::class]);

    $this->branch = Branch::factory()->create();
    $this->rider = msgStaff(RoleEnum::Rider->value, [$this->branch]);
});

/** A sent message with one recipient row for the given user. */
function msgDeliveredTo($user, array $attributes = []): StaffMessageRecipient
{
    $message = StaffMessage::factory()->create($attributes);

    return StaffMessageRecipient::create([
        'staff_message_id' => $message->id,
        'user_id' => $user->id,
    ]);
}

it('shows a staff member only their own messages', function () {
    $mine = msgDeliveredTo($this->rider);
    $theirs = msgDeliveredTo(msgStaff(RoleEnum::Rider->value, [$this->branch]));

    $ids = $this->actingAs($this->rider)
        ->getJson('/v1/messages/inbox')
        ->assertSuccessful()
        ->json('data.*.id');

    expect($ids)->toContain($mine->id)
        ->and($ids)->not->toContain($theirs->id);
});

it('404s rather than 403s when somebody reaches for another person\'s message', function () {
    $theirs = msgDeliveredTo(msgStaff(RoleEnum::Rider->value, [$this->branch]));

    // 404, not 403: a 403 confirms the row exists, which is itself a leak.
    $this->actingAs($this->rider)
        ->getJson("/v1/messages/inbox/{$theirs->id}")
        ->assertNotFound();
});

it('marks a message read when it is opened', function () {
    $row = msgDeliveredTo($this->rider);

    $this->actingAs($this->rider)->getJson("/v1/messages/inbox/{$row->id}")->assertSuccessful();

    expect($row->refresh()->read_at)->not->toBeNull();
});

it('does not move the first-read time when a message is opened again', function () {
    $row = msgDeliveredTo($this->rider);

    $this->actingAs($this->rider)->getJson("/v1/messages/inbox/{$row->id}");
    $first = $row->refresh()->read_at;

    $this->travel(10)->minutes();
    $this->actingAs($this->rider)->getJson("/v1/messages/inbox/{$row->id}");

    expect($row->refresh()->read_at->timestamp)->toBe($first->timestamp);
});

it('treats acknowledging as having read it', function () {
    // Straight from a push notification — the inbox was never opened.
    $row = msgDeliveredTo($this->rider, ['kind' => StaffMessageKind::Caution->value, 'requires_acknowledgement' => true]);

    $this->actingAs($this->rider)
        ->postJson("/v1/messages/inbox/{$row->id}/acknowledge")
        ->assertSuccessful();

    expect($row->refresh()->acknowledged_at)->not->toBeNull()
        ->and($row->read_at)->not->toBeNull();
});

it('accepts one of the offered quick replies', function () {
    $row = msgDeliveredTo($this->rider, ['quick_replies' => ['Got it', 'Understood']]);

    $this->actingAs($this->rider)
        ->postJson("/v1/messages/inbox/{$row->id}/reply", ['quick_reply' => 'Got it'])
        ->assertSuccessful();

    expect($row->refresh()->quick_reply)->toBe('Got it');
});

it('refuses a quick reply that was never offered', function () {
    $row = msgDeliveredTo($this->rider, ['quick_replies' => ['Got it']]);

    $this->actingAs($this->rider)
        ->postJson("/v1/messages/inbox/{$row->id}/reply", ['quick_reply' => 'No chance'])
        ->assertStatus(422);
});

it('enforces the custom-reply toggle on the server, not just in the UI', function () {
    $row = msgDeliveredTo($this->rider, [
        'allow_custom_reply' => false,
        'quick_replies' => ['Got it'],
    ]);

    $this->actingAs($this->rider)
        ->postJson("/v1/messages/inbox/{$row->id}/reply", ['body' => 'I disagree, at length.'])
        ->assertStatus(422);

    expect($row->refresh()->reply_body)->toBeNull();
});

it('takes a custom reply when the message allows one', function () {
    $row = msgDeliveredTo($this->rider, ['allow_custom_reply' => true]);

    $this->actingAs($this->rider)
        ->postJson("/v1/messages/inbox/{$row->id}/reply", ['body' => 'The kitchen ran out of jollof.'])
        ->assertSuccessful();

    expect($row->refresh()->reply_body)->toBe('The kitchen ran out of jollof.');
});

it('hides an expired message from the inbox', function () {
    msgDeliveredTo($this->rider, ['expires_at' => now()->subMinute()]);

    expect($this->actingAs($this->rider)->getJson('/v1/messages/inbox')->json('data'))->toBeEmpty();
});

it('gives the bell an unread count and the pending cautions in one call', function () {
    msgDeliveredTo($this->rider, ['kind' => StaffMessageKind::Caution->value, 'requires_acknowledgement' => true]);
    msgDeliveredTo($this->rider, ['kind' => StaffMessageKind::Notice->value]);

    $body = $this->actingAs($this->rider)
        ->getJson('/v1/messages/inbox/summary')
        ->assertSuccessful()
        ->json('data');

    // Both are unread; only the caution is pending an acknowledgement.
    expect($body['unread'])->toBe(2)
        ->and($body['pending'])->toHaveCount(1);
});

it('drops a caution out of pending once it is acknowledged', function () {
    $row = msgDeliveredTo($this->rider, ['kind' => StaffMessageKind::Caution->value, 'requires_acknowledgement' => true]);

    $this->actingAs($this->rider)->postJson("/v1/messages/inbox/{$row->id}/acknowledge");

    expect($this->actingAs($this->rider)->getJson('/v1/messages/inbox/summary')->json('data.pending'))
        ->toBeEmpty();
});

it('lets a staff member raise something with the IT team', function () {
    $admin = msgSender(RoleEnum::Admin->value);

    $this->actingAs($this->rider)
        ->postJson('/v1/messages/raise', ['subject' => 'POS freezing', 'body' => 'The till freezes on payment.'])
        ->assertSuccessful();

    $query = StaffMessage::where('kind', StaffMessageKind::StaffQuery->value)->first();

    expect($query->sender_user_id)->toBe($this->rider->id)
        ->and($query->recipients()->pluck('user_id')->all())->toContain($admin->id);
});

it('does not need any permission to read your own inbox', function () {
    $row = msgDeliveredTo($this->rider);

    // A rider holds no messaging permission at all and must still get here.
    expect($this->rider->can('staff_messages.manage'))->toBeFalse();

    $this->actingAs($this->rider)->getJson("/v1/messages/inbox/{$row->id}")->assertSuccessful();
});
