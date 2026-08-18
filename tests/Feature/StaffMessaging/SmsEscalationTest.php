<?php

use App\Enums\Role as RoleEnum;
use App\Events\StaffMessageEvent;
use App\Jobs\EscalateStaffMessageToSms;
use App\Models\Branch;
use App\Models\StaffMessage;
use App\Models\StaffMessageRecipient;
use App\Services\HubtelSmsService;
use App\Services\StaffMessaging\StaffMessageDispatcher;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| SMS is the last rung, not a parallel send
|--------------------------------------------------------------------------
|
| The job re-reads read_at when it runs, so queuing it is not a decision to send
| — it is a decision to check later. Everything here pins that distinction down,
| because getting it wrong means paying Hubtel to tell somebody about a message
| already open on the screen in front of them.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    Notification::fake();
    Event::fake([StaffMessageEvent::class]);

    // Both are load-bearing. The queue runs synchronously under test, so without
    // Bus::fake() every dispatcher call would run the escalation job immediately
    // — and preventStrayRequests turns any remaining leak into a loud failure
    // rather than a real SMS billed to the account.
    Bus::fake();
    Http::preventStrayRequests();

    $this->rider = msgStaff(RoleEnum::Rider->value, [Branch::factory()->create()]);
    $this->rider->update(['phone' => '0241234567']);
});

function msgWithFallback($user, ?int $minutes): StaffMessageRecipient
{
    $message = StaffMessage::factory()->unsent()->create([
        'sms_fallback_after_minutes' => $minutes,
    ]);

    app(StaffMessageDispatcher::class)->send($message, collect([$user]));

    return StaffMessageRecipient::where('staff_message_id', $message->id)->first();
}

it('queues an escalation when the message asks for one', function () {
    msgWithFallback($this->rider, 30);

    Bus::assertDispatched(EscalateStaffMessageToSms::class);
});

it('queues nothing when the message asks for no fallback', function () {
    msgWithFallback($this->rider, null);

    Bus::assertNotDispatched(EscalateStaffMessageToSms::class);
});

it('sends nothing when the message was read before the window closed', function () {
    $row = msgWithFallback($this->rider, 30);
    $row->markRead();

    // The service is never touched — the assertion is that it is not called.
    $sms = Mockery::mock(HubtelSmsService::class);
    $sms->shouldNotReceive('sendSingle');

    (new EscalateStaffMessageToSms($row->id))->handle($sms);

    expect($row->refresh()->sms_sent_at)->toBeNull();
});

it('sends when the message is still unread', function () {
    $row = msgWithFallback($this->rider, 30);

    $sms = Mockery::mock(HubtelSmsService::class);
    $sms->shouldReceive('sendSingle')
        // Hubtel wants 233… with no plus.
        ->once()
        ->with('233241234567', Mockery::type('string'), 'staff_message')
        ->andReturn(['messageId' => 'abc', 'status' => 0]);

    (new EscalateStaffMessageToSms($row->id))->handle($sms);

    expect($row->refresh()->sms_sent_at)->not->toBeNull()
        ->and($row->sms_status)->toBe('sent');
});

it('does not send twice when the job is retried', function () {
    $row = msgWithFallback($this->rider, 30);
    $sentAt = now()->subMinutes(5);
    $row->forceFill(['sms_sent_at' => $sentAt, 'sms_status' => 'sent'])->save();

    $sms = Mockery::mock(HubtelSmsService::class);
    $sms->shouldNotReceive('sendSingle');

    (new EscalateStaffMessageToSms($row->id))->handle($sms);

    // Untouched — a second text costs money and reads as the system panicking.
    expect($row->refresh()->sms_sent_at->timestamp)->toBe($sentAt->timestamp);
});

it('does not text about a message that expired while it sat unread', function () {
    $row = msgWithFallback($this->rider, 30);
    $row->message->update(['expires_at' => now()->subMinute()]);

    $sms = Mockery::mock(HubtelSmsService::class);
    $sms->shouldNotReceive('sendSingle');

    (new EscalateStaffMessageToSms($row->id))->handle($sms);

    expect($row->refresh()->sms_status)->toBe('skipped_expired');
});

it('records that there was no number to text', function () {
    $this->rider->update(['phone' => null]);
    $row = msgWithFallback($this->rider, 30);

    $sms = Mockery::mock(HubtelSmsService::class);
    $sms->shouldNotReceive('sendSingle');

    (new EscalateStaffMessageToSms($row->id))->handle($sms);

    expect($row->refresh()->sms_status)->toBe('no_phone');
});

it('carries an id rather than a serialised model', function () {
    // The row's state at queue time is exactly what must NOT be trusted — the
    // job exists to re-read read_at. A serialised model would freeze it.
    $job = new EscalateStaffMessageToSms(42);

    expect($job->recipientId)->toBe(42);
});
