<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Enums\StaffMessageKind;
use App\Events\StaffMessageEvent;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\StaffMessage;
use App\Models\User;
use App\Services\StaffMessaging\StaffMessageDispatcher;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

// msgSender() and msgStaff() live in tests/Pest.php.

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    Notification::fake();
    Event::fake([StaffMessageEvent::class]);
});

it('lets an admin send to a role and records a receipt per person', function () {
    $branch = Branch::factory()->create();
    $admin = msgSender(RoleEnum::Admin->value);

    $riderOne = msgStaff(RoleEnum::Rider->value, [$branch]);
    $riderTwo = msgStaff(RoleEnum::Rider->value, [$branch]);

    $this->actingAs($admin)
        ->postJson('/v1/admin/messages', [
            'kind' => StaffMessageKind::Notice->value,
            'subject' => 'Fuel claims',
            'body' => 'Fuel claims are due on Friday.',
            'audience' => ['roles' => [RoleEnum::Rider->value]],
        ])
        ->assertSuccessful();

    $message = StaffMessage::latest('id')->first();

    expect($message->recipient_count)->toBe(2)
        ->and($message->recipients()->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$riderOne->id, $riderTwo->id])->sort()->values()->all());
});

it('refuses a manager, who does not send', function () {
    $manager = msgSender(RoleEnum::Manager->value);

    $this->actingAs($manager)
        ->postJson('/v1/admin/messages', [
            'kind' => StaffMessageKind::Notice->value,
            'body' => 'Anything at all.',
            'audience' => ['everyone' => true],
        ])
        ->assertForbidden();
});

it('refuses a send with nobody selected', function () {
    $admin = msgSender(RoleEnum::Admin->value);

    $this->actingAs($admin)
        ->postJson('/v1/admin/messages', [
            'kind' => StaffMessageKind::Notice->value,
            'body' => 'Body.',
            'audience' => [],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('audience');
});

it('refuses an audience that currently matches nobody rather than silently sending nothing', function () {
    $admin = msgSender(RoleEnum::Admin->value);

    $this->actingAs($admin)
        ->postJson('/v1/admin/messages', [
            'kind' => StaffMessageKind::Notice->value,
            'body' => 'Body.',
            'audience' => ['roles' => [RoleEnum::Rider->value]],
        ])
        ->assertStatus(422);
});

it('refuses a notice that asks to be acknowledged', function () {
    $admin = msgSender(RoleEnum::Admin->value);
    msgStaff(RoleEnum::Rider->value, [Branch::factory()->create()]);

    $this->actingAs($admin)
        ->postJson('/v1/admin/messages', [
            'kind' => StaffMessageKind::Notice->value,
            'body' => 'Body.',
            'requires_acknowledgement' => true,
            'audience' => ['roles' => [RoleEnum::Rider->value]],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('requires_acknowledgement');
});

it('reports the audience size before anything is sent', function () {
    $branch = Branch::factory()->create();
    $admin = msgSender(RoleEnum::Admin->value);
    msgStaff(RoleEnum::Rider->value, [$branch]);
    msgStaff(RoleEnum::Rider->value, [$branch]);

    $this->actingAs($admin)
        ->postJson('/v1/admin/messages/preview', [
            'audience' => ['roles' => [RoleEnum::Rider->value]],
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.count', 2);

    expect(StaffMessage::count())->toBe(0);
});

it('does not write a second receipt when a dispatch is repeated', function () {
    $branch = Branch::factory()->create();
    $rider = msgStaff(RoleEnum::Rider->value, [$branch]);
    $message = StaffMessage::factory()->unsent()->create();

    $dispatcher = app(StaffMessageDispatcher::class);
    $dispatcher->send($message, collect([$rider]));
    $dispatcher->send($message->refresh(), collect([$rider]));

    expect($message->recipients()->count())->toBe(1);
});

it('stamps sent_at once and does not move it on a repeat', function () {
    $rider = msgStaff(RoleEnum::Rider->value, [Branch::factory()->create()]);
    $message = StaffMessage::factory()->unsent()->create();

    $dispatcher = app(StaffMessageDispatcher::class);
    $dispatcher->send($message, collect([$rider]));
    $first = $message->refresh()->sent_at;

    $this->travel(5)->minutes();
    $dispatcher->send($message->refresh(), collect([$rider]));

    expect($message->refresh()->sent_at->timestamp)->toBe($first->timestamp);
});

it('survives an empty audience at dispatch without throwing', function () {
    $message = StaffMessage::factory()->unsent()->create();

    app(StaffMessageDispatcher::class)->send($message, collect());

    expect($message->refresh()->recipient_count)->toBe(0)
        ->and($message->sent_at)->not->toBeNull();
});
