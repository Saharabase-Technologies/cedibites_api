<?php

use App\Enums\Role as RoleEnum;
use App\Enums\StaffMessageKind;
use App\Events\StaffMessageEvent;
use App\Models\Branch;
use App\Models\StaffMessage;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| "What's new" — a message that is paged through
|--------------------------------------------------------------------------
|
| A release note covers several unrelated changes, each usually wanting its own
| picture, so it is slides rather than one body of markdown. It interrupts, like
| a caution, because somebody who has not been told the rules changed goes on
| working to the old ones — and it keeps interrupting at every sign-in until
| they have actually been through it.
|
| The two things that must not happen: the same release going out twice, and a
| walkthrough arriving with nothing to walk through.
|
*/

// msgSender() and msgStaff() live in tests/Pest.php.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    Notification::fake();
    Event::fake([StaffMessageEvent::class]);

    $this->branch = Branch::factory()->create();
    $this->admin = msgSender(RoleEnum::Admin->value);
});

function releasePayload(array $override = []): array
{
    return array_merge([
        'kind' => StaffMessageKind::Release->value,
        'release_key' => 'orders-and-till-2026-08',
        'subject' => "What's new: orders and the till",
        'body' => 'Three things changed on the orders screen.',
        'audience' => ['roles' => [RoleEnum::SalesStaff->value]],
        'requires_acknowledgement' => true,
        'steps' => [
            ['title' => 'Online orders reach the till', 'body' => 'They arrive with a sound.'],
            ['title' => 'Accepting is claiming', 'body' => 'Your name goes on the order.'],
            ['title' => 'Print vs Reprint', 'body' => 'Print means never printed.'],
        ],
    ], $override);
}

it('sends a walkthrough and keeps the slides in the order they were given', function () {
    msgStaff(RoleEnum::SalesStaff->value, [$this->branch]);

    $this->actingAs($this->admin)
        ->postJson('/v1/admin/messages', releasePayload())
        ->assertSuccessful();

    $message = StaffMessage::where('release_key', 'orders-and-till-2026-08')->firstOrFail();

    expect($message->kind)->toBe(StaffMessageKind::Release)
        ->and($message->steps)->toHaveCount(3)
        ->and($message->steps->pluck('position')->all())->toBe([1, 2, 3])
        ->and($message->steps->pluck('title')->first())->toBe('Online orders reach the till');
});

it('refuses to send the same release twice', function () {
    msgStaff(RoleEnum::SalesStaff->value, [$this->branch]);

    $this->actingAs($this->admin)->postJson('/v1/admin/messages', releasePayload())->assertSuccessful();

    $this->actingAs($this->admin)
        ->postJson('/v1/admin/messages', releasePayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors('release_key');

    expect(StaffMessage::where('release_key', 'orders-and-till-2026-08')->count())->toBe(1);
});

it('refuses a walkthrough with nothing to walk through', function () {
    msgStaff(RoleEnum::SalesStaff->value, [$this->branch]);

    $this->actingAs($this->admin)
        ->postJson('/v1/admin/messages', releasePayload(['steps' => []]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('steps');
});

it('refuses slides on a kind that cannot page them', function () {
    msgStaff(RoleEnum::SalesStaff->value, [$this->branch]);

    $this->actingAs($this->admin)
        ->postJson('/v1/admin/messages', releasePayload([
            'kind' => StaffMessageKind::Notice->value,
            'release_key' => null,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('steps');
});

it('puts the walkthrough in the pending set beside cautions', function () {
    $cashier = msgStaff(RoleEnum::SalesStaff->value, [$this->branch]);

    $this->actingAs($this->admin)->postJson('/v1/admin/messages', releasePayload())->assertSuccessful();

    $body = $this->actingAs($cashier)->getJson('/v1/messages/inbox/summary')->assertSuccessful()->json();

    expect($body['data']['pending'])->toHaveCount(1)
        ->and($body['data']['pending'][0]['kind'])->toBe('release')
        ->and($body['data']['pending'][0]['interrupts'])->toBeTrue()
        ->and($body['data']['pending'][0]['steps'])->toHaveCount(3);
});

it('stops interrupting once the person has been through it', function () {
    $cashier = msgStaff(RoleEnum::SalesStaff->value, [$this->branch]);

    $this->actingAs($this->admin)->postJson('/v1/admin/messages', releasePayload())->assertSuccessful();

    $pending = $this->actingAs($cashier)->getJson('/v1/messages/inbox/summary')->json('data.pending');
    $recipientId = $pending[0]['id'];

    $this->actingAs($cashier)
        ->postJson("/v1/messages/inbox/{$recipientId}/acknowledge")
        ->assertSuccessful();

    expect($this->actingAs($cashier)->getJson('/v1/messages/inbox/summary')->json('data.pending'))
        ->toHaveCount(0);
});

it('sends no slides on an ordinary notice', function () {
    $cashier = msgStaff(RoleEnum::SalesStaff->value, [$this->branch]);

    $this->actingAs($this->admin)
        ->postJson('/v1/admin/messages', [
            'kind' => StaffMessageKind::Caution->value,
            'subject' => 'Till drawer',
            'body' => 'Count it before you close.',
            'audience' => ['roles' => [RoleEnum::SalesStaff->value]],
        ])
        ->assertSuccessful();

    $pending = $this->actingAs($cashier)->getJson('/v1/messages/inbox/summary')->json('data.pending');

    expect($pending[0]['kind'])->toBe('caution')
        ->and($pending[0]['steps'])->toBeNull();
});
