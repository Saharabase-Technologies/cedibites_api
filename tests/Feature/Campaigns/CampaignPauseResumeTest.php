<?php

use App\Enums\CampaignRecipientState;
use App\Enums\CampaignStatus;
use App\Enums\SmsFailureReason;
use App\Exceptions\SmsBatchRefused;
use App\Jobs\SendCampaignChunk;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SmsDeliveryAttempt;
use App\Models\User;
use App\Notifications\CampaignPausedNotification;
use App\Services\Campaigns\CampaignLedger;
use App\Services\Campaigns\CampaignSender;
use App\Services\HubtelSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HubtelRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

/*
 * What this file is about.
 *
 * On 2026-10-01 the first real campaign on production, "New Month", reached 39
 * of 3,539 people. The Hubtel account was short of credit. Hubtel refused seven
 * chunks of 500 with `{"batchId":null,"status":12,"data":null}`, the code filed
 * each one as an unrecognised error, kept firing the next chunk into the same
 * refusal, marked the campaign Sent, and left no way to reach the other 3,500.
 *
 * Every test here is one of those faults, closed.
 */

beforeEach(function () {
    Order::query()->forceDelete();
    Customer::query()->forceDelete();
    User::query()->forceDelete();

    SpatieRole::findOrCreate('admin', 'api')
        ->givePermissionTo(SpatiePermission::findOrCreate('manage_campaigns', 'api'));
    SpatieRole::findOrCreate('manager', 'api');

    config([
        'campaigns.seed_mode' => false,
        'campaigns.seed_list' => [],
        'campaigns.recipient_cap' => 0,
        'campaigns.chunk_size' => 2,
        'campaigns.inter_batch_delay_seconds' => 0,
        'campaigns.send_window.enabled' => false,
        'campaigns.rate_limit_retries' => 2,
        'campaigns.rate_limit_retry_seconds' => 1,
        'services.hubtel.client_id' => 'test-id',
        'services.hubtel.client_secret' => 'test-secret',
    ]);

    Notification::fake();
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

function pauseAdmin(): User
{
    $existing = User::where('phone', '+233200000009')->first();

    if ($existing) {
        return $existing;
    }

    $user = User::factory()->create(['phone' => '+233200000009', 'email' => 'sender@example.com']);
    $user->assignRole('admin');

    return $user;
}

/** Five guests who have each ordered once, so "everyone" is five numbers. */
function pauseAudience(): array
{
    $phones = ['+233241111111', '+233242222222', '+233243333333', '+233244444444', '+233245555555'];

    foreach ($phones as $phone) {
        Order::factory()->create([
            'customer_id' => null,
            'contact_name' => 'Ama',
            'contact_phone' => $phone,
            'status' => 'completed',
            'created_at' => now()->subDay(),
        ]);
    }

    return $phones;
}

function pauseDraft(): Campaign
{
    return Campaign::factory()->create(['created_by_user_id' => pauseAdmin()->id]);
}

/** Hubtel taking a batch: 201, a batch id, one entry per recipient. */
function hubtelAccepts(string $batchId = 'batch-1'): \GuzzleHttp\Promise\PromiseInterface
{
    return Http::response([
        'batchId' => $batchId,
        'status' => 0,
        'data' => [['recipient' => '233241111111', 'content' => 'x', 'messageId' => 'm-'.$batchId]],
    ], 201);
}

/** Hubtel refusing a batch for want of credit, exactly as it did on the day. */
function hubtelOutOfCredit(): \GuzzleHttp\Promise\PromiseInterface
{
    return Http::response(['batchId' => null, 'status' => 12, 'data' => null], 400);
}

/** Every number Hubtel was asked to send to, across every request. */
function numbersSentToHubtel(): array
{
    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (HubtelRequest $request) => str_contains($request->url(), 'batch/simple/send'))
        ->flatMap(fn (HubtelRequest $request) => $request->data()['Recipients'] ?? [])
        ->values()
        ->all();
}

function statesOf(Campaign $campaign): array
{
    return CampaignRecipient::where('campaign_id', $campaign->id)
        ->selectRaw('state, count(*) as total')
        ->groupBy('state')
        ->pluck('total', 'state')
        ->all();
}

// ─── Reading what Hubtel said ────────────────────────────────────────────────

describe('reading a refusal', function () {
    /*
     * The fault the whole incident grew from. The batch endpoint says "no
     * credit" as a number, with no words, and the code only read words.
     */
    it('reads batch status 12 as no credit', function () {
        Http::fake(['*' => hubtelOutOfCredit()]);

        $thrown = null;

        try {
            app(HubtelSmsService::class)->sendBatch(['233241111111'], 'Hello', isCampaign: true);
        } catch (SmsBatchRefused $e) {
            $thrown = $e;
        }

        expect($thrown)->not->toBeNull()
            ->and($thrown->reason)->toBe(SmsFailureReason::NoCredit)
            ->and(SmsDeliveryAttempt::first()->failure_reason)->toBe(SmsFailureReason::NoCredit);
    });

    it('reads the other documented status numbers', function (int $status, SmsFailureReason $expected) {
        Http::fake(['*' => Http::response(['batchId' => null, 'status' => $status, 'data' => null], 400)]);

        expect(fn () => app(HubtelSmsService::class)->sendBatch(['233241111111'], 'Hello'))
            ->toThrow(fn (SmsBatchRefused $e) => expect($e->reason)->toBe($expected));
    })->with([
        'invalid destination' => [1, SmsFailureReason::InvalidRecipient],
        'not routable' => [4, SmsFailureReason::InvalidRecipient],
        'malformed request' => [100, SmsFailureReason::Unknown],
        'a number nobody has documented' => [77, SmsFailureReason::Unknown],
    ]);

    /*
     * A 5xx is not a refusal. Something broke on their side and the batch may
     * or may not exist, so it must not be handed back as safe to resend.
     */
    it('does not call a server error a refusal', function () {
        Http::fake(['*' => Http::response('Bad gateway', 502)]);

        expect(fn () => app(HubtelSmsService::class)->sendBatch(['233241111111'], 'Hello'))
            ->toThrow(fn (\Exception $e) => expect($e)->not->toBeInstanceOf(SmsBatchRefused::class));
    });
});

// ─── Pausing ─────────────────────────────────────────────────────────────────

describe('pausing', function () {
    /*
     * Seven chunks went into the same refusal on the day. One is enough to
     * know, and the rest of the list should be held, not burned.
     */
    it('stops at the first chunk refused for credit and holds the rest unsent', function () {
        pauseAudience();
        Http::fake(['*' => hubtelOutOfCredit()]);

        $campaign = pauseDraft();

        $this->actingAs(pauseAdmin(), 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/send")
            ->assertSuccessful();

        $campaign->refresh();

        expect($campaign->status)->toBe(CampaignStatus::Paused)
            ->and($campaign->pause_reason)->toBe(SmsFailureReason::NoCredit)
            ->and($campaign->sent_count)->toBe(0)
            // Not failed. Nothing was sent, so nobody is written off.
            ->and($campaign->failed_count)->toBe(0)
            ->and($campaign->completed_at)->toBeNull()
            ->and(statesOf($campaign))->toBe(['queued' => 5]);

        // Three chunks of two, and Hubtel heard from exactly one of them.
        Http::assertSentCount(1);
    });

    it('tells whoever pressed send, by email and never by text', function () {
        pauseAudience();
        Http::fake(['*' => hubtelOutOfCredit()]);

        $campaign = pauseDraft();

        $this->actingAs(pauseAdmin(), 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/send")
            ->assertSuccessful();

        Notification::assertSentToTimes(pauseAdmin(), CampaignPausedNotification::class, 1);

        Notification::assertSentTo(
            pauseAdmin(),
            CampaignPausedNotification::class,
            // An alert that SMS is out of credit cannot travel by SMS.
            fn (CampaignPausedNotification $n, array $channels) => $channels === ['database', 'mail']
                && $n->reason === SmsFailureReason::NoCredit,
        );
    });

    it('says why and what to do on the campaign itself', function () {
        pauseAudience();
        Http::fake(['*' => hubtelOutOfCredit()]);

        $campaign = pauseDraft();

        $this->actingAs(pauseAdmin(), 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/send")
            ->assertSuccessful();

        $this->actingAs(pauseAdmin(), 'sanctum')
            ->getJson("/v1/admin/campaigns/{$campaign->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.status_label', 'Paused')
            ->assertJsonPath('data.pause_reason', 'no_credit')
            ->assertJsonPath('data.pause_reason_label', 'SMS account out of credit')
            ->assertJsonPath('data.waiting_count', 5)
            ->assertJsonPath('data.resumable_count', 5)
            ->assertJsonPath('data.can_resume', true);
    });

    /*
     * A bad number in one chunk says nothing about the next chunk. That one
     * is written off and the campaign carries on.
     */
    it('carries on past a chunk refused for the numbers in it', function () {
        pauseAudience();

        Http::fakeSequence()
            ->push(['batchId' => null, 'status' => 1, 'data' => null], 400)
            ->pushResponse(hubtelAccepts('b2'))
            ->pushResponse(hubtelAccepts('b3'));

        $campaign = pauseDraft();

        $this->actingAs(pauseAdmin(), 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/send")
            ->assertSuccessful();

        $campaign->refresh();

        expect($campaign->status)->toBe(CampaignStatus::PartlySent)
            ->and($campaign->sent_count)->toBe(3)
            ->and($campaign->failed_count)->toBe(2)
            ->and(statesOf($campaign))->toBe(['accepted' => 3, 'refused' => 2]);

        Notification::assertNothingSent();
    });
});

// ─── Resuming ────────────────────────────────────────────────────────────────

describe('resuming', function () {
    /*
     * The day replayed, with the ending it should have had. Credit runs out
     * part way, somebody tops up, and the rest goes out. Nobody gets it twice.
     */
    it('sends to exactly the people who were missed', function () {
        $everyone = pauseAudience();

        Http::fakeSequence()
            ->pushResponse(hubtelAccepts('b1'))
            ->pushResponse(hubtelOutOfCredit())
            // After the top-up.
            ->pushResponse(hubtelAccepts('b2'))
            ->pushResponse(hubtelAccepts('b3'));

        $campaign = pauseDraft();

        $this->actingAs(pauseAdmin(), 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/send")
            ->assertSuccessful();

        expect($campaign->fresh()->status)->toBe(CampaignStatus::Paused)
            ->and($campaign->fresh()->sent_count)->toBe(2)
            ->and(statesOf($campaign))->toBe(['accepted' => 2, 'queued' => 3]);

        $this->actingAs(pauseAdmin(), 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/resume")
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.can_resume', false);

        $campaign->refresh();

        expect($campaign->status)->toBe(CampaignStatus::Sent)
            ->and($campaign->sent_count)->toBe(5)
            ->and($campaign->failed_count)->toBe(0)
            ->and($campaign->pause_reason)->toBeNull()
            ->and($campaign->batch_ids)->toBe(['b1', 'b2', 'b3']);

        $accepted = array_filter(
            array_count_values(numbersSentToHubtel()),
            fn (int $times) => $times > 1,
        );

        // Only the refused chunk's two numbers were ever offered twice, and
        // Hubtel took them once. Everyone ends up accepted exactly once.
        expect(array_keys($accepted))->toHaveCount(2)
            ->and(statesOf($campaign))->toBe(['accepted' => 5])
            ->and(count(array_unique(numbersSentToHubtel())))->toBe(count($everyone));
    });

    it('pauses again, without loss, when the cause has not been fixed', function () {
        pauseAudience();
        Http::fake(['*' => hubtelOutOfCredit()]);

        $campaign = pauseDraft();

        $this->actingAs(pauseAdmin(), 'sanctum')->postJson("/v1/admin/campaigns/{$campaign->id}/send");
        $this->actingAs(pauseAdmin(), 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/resume")
            ->assertSuccessful();

        expect($campaign->fresh()->status)->toBe(CampaignStatus::Paused)
            ->and($campaign->fresh()->failed_count)->toBe(0)
            ->and(statesOf($campaign))->toBe(['queued' => 5]);
    });

    it('writes down who resumed it', function () {
        pauseAudience();

        Http::fakeSequence()
            ->pushResponse(hubtelOutOfCredit())
            ->whenEmpty(hubtelAccepts());

        $campaign = pauseDraft();

        $this->actingAs(pauseAdmin(), 'sanctum')->postJson("/v1/admin/campaigns/{$campaign->id}/send");
        $this->actingAs(pauseAdmin(), 'sanctum')->postJson("/v1/admin/campaigns/{$campaign->id}/resume");

        $this->assertDatabaseHas('activity_log', [
            'event' => 'campaign_sent',
            'causer_id' => pauseAdmin()->id,
            'subject_id' => $campaign->id,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'campaign_resumed',
            'causer_id' => pauseAdmin()->id,
            'subject_id' => $campaign->id,
        ]);
    });

    it('has nothing to resume on a campaign that reached everybody', function () {
        pauseAudience();
        Http::fake(['*' => hubtelAccepts()]);

        $campaign = pauseDraft();

        $this->actingAs(pauseAdmin(), 'sanctum')->postJson("/v1/admin/campaigns/{$campaign->id}/send");

        expect($campaign->fresh()->status)->toBe(CampaignStatus::Sent);

        $this->actingAs(pauseAdmin(), 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/resume")
            ->assertUnprocessable();
    });

    it('refuses a manager', function () {
        $manager = User::factory()->create(['phone' => '+233209999998']);
        $manager->assignRole('manager');

        $campaign = Campaign::factory()->create([
            'created_by_user_id' => pauseAdmin()->id,
            'status' => CampaignStatus::Paused,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/resume")
            ->assertForbidden();
    });
});

// ─── When nobody can say whether it went ─────────────────────────────────────

describe('no clear answer', function () {
    /*
     * A timeout is the dangerous one. The request may have landed, so those
     * people are never sent to again unless a person decides to.
     */
    it('marks a timed out chunk as unsure and does not resend it by default', function () {
        pauseAudience();

        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds'));

        $campaign = pauseDraft();

        $this->actingAs(pauseAdmin(), 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/send")
            ->assertSuccessful();

        $campaign->refresh();

        expect($campaign->status)->toBe(CampaignStatus::Paused)
            ->and($campaign->failed_count)->toBe(2)
            ->and(statesOf($campaign))->toBe(['queued' => 3, 'unsure' => 2]);

        $ledger = app(CampaignLedger::class);

        expect($ledger->resumableCount($campaign->id))->toBe(3)
            ->and($ledger->resumableCount($campaign->id, includeUnsure: true))->toBe(5);
    });

    /*
     * A name that would not resolve never left the building. That is as good
     * as a refusal, and the chunk can simply go again.
     */
    it('treats a connection that never opened as nothing sent', function () {
        pauseAudience();

        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: sms.hubtel.com'));

        $campaign = pauseDraft();

        $this->actingAs(pauseAdmin(), 'sanctum')->postJson("/v1/admin/campaigns/{$campaign->id}/send");

        $campaign->refresh();

        expect($campaign->status)->toBe(CampaignStatus::Paused)
            ->and($campaign->pause_reason)->toBe(SmsFailureReason::Connection)
            ->and($campaign->failed_count)->toBe(0)
            ->and(statesOf($campaign))->toBe(['queued' => 5]);
    });

    it('sends to the unsure only when asked to', function () {
        pauseAudience();

        $calls = 0;

        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return hubtelAccepts('b'.$calls);
        });

        $campaign = pauseDraft();

        $this->actingAs(pauseAdmin(), 'sanctum')->postJson("/v1/admin/campaigns/{$campaign->id}/send");

        $this->actingAs(pauseAdmin(), 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/resume")
            ->assertSuccessful();

        // The three who were held went out. The two unsure were left alone.
        expect($campaign->fresh()->status)->toBe(CampaignStatus::PartlySent)
            ->and(statesOf($campaign))->toBe(['accepted' => 3, 'unsure' => 2]);

        $this->actingAs(pauseAdmin(), 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/resume", ['include_unsure' => true])
            ->assertSuccessful();

        expect($campaign->fresh()->status)->toBe(CampaignStatus::Sent)
            ->and($campaign->fresh()->failed_count)->toBe(0)
            ->and(statesOf($campaign))->toBe(['accepted' => 5]);
    });

    /*
     * A worker killed mid-chunk used to leave the campaign in Sending for
     * good. Now the rows it held are marked and the campaign pauses.
     */
    it('settles a chunk whose worker died', function () {
        $campaign = Campaign::factory()->sending()->create([
            'created_by_user_id' => pauseAdmin()->id,
            'recipient_count' => 2,
        ]);

        $ledger = app(CampaignLedger::class);
        $ledger->open($campaign, ['233241111111', '233242222222']);
        $ledger->claim($campaign->id, ['233241111111', '233242222222']);

        (new SendCampaignChunk($campaign->id, ['233241111111', '233242222222'], 'Hello'))
            ->failed(new \RuntimeException('Job has timed out'));

        expect(statesOf($campaign))->toBe(['unsure' => 2])
            ->and($campaign->fresh()->failed_count)->toBe(2)
            // Everyone is accounted for, so it is finished, and it reached nobody.
            ->and($campaign->fresh()->status)->toBe(CampaignStatus::Failed);
    });
});

// ─── The one refusal that is retried without a person ────────────────────────

describe('being told to slow down', function () {
    it('queues the same chunk again and sends it once', function () {
        config(['campaigns.chunk_size' => 5]);
        pauseAudience();

        Http::fakeSequence()
            ->push(['statusDescription' => 'Too many requests'], 429)
            ->pushResponse(hubtelAccepts());

        $campaign = pauseDraft();

        $this->actingAs(pauseAdmin(), 'sanctum')->postJson("/v1/admin/campaigns/{$campaign->id}/send");

        expect($campaign->fresh()->status)->toBe(CampaignStatus::Sent)
            ->and($campaign->fresh()->sent_count)->toBe(5);

        Http::assertSentCount(2);
    });

    it('gives up and pauses when it keeps being told', function () {
        config(['campaigns.chunk_size' => 5]);
        pauseAudience();

        Http::fake(['*' => Http::response(['statusDescription' => 'Too many requests'], 429)]);

        $campaign = pauseDraft();

        $this->actingAs(pauseAdmin(), 'sanctum')->postJson("/v1/admin/campaigns/{$campaign->id}/send");

        expect($campaign->fresh()->status)->toBe(CampaignStatus::Paused)
            ->and($campaign->fresh()->pause_reason)->toBe(SmsFailureReason::RateLimited)
            ->and(statesOf($campaign))->toBe(['queued' => 5]);

        // The first try and two more.
        Http::assertSentCount(3);
    });
});

// ─── Safety of the chunk itself ──────────────────────────────────────────────

it('sends a chunk once however many times its job runs', function () {
    Http::fake(['*' => hubtelAccepts()]);

    $campaign = Campaign::factory()->sending()->create([
        'created_by_user_id' => pauseAdmin()->id,
        'recipient_count' => 2,
    ]);

    app(CampaignLedger::class)->open($campaign, ['233241111111', '233242222222']);

    $job = fn () => (new SendCampaignChunk($campaign->id, ['233241111111', '233242222222'], 'Hello'))
        ->handle(app(HubtelSmsService::class), app(CampaignSender::class));

    $job();
    $job();

    Http::assertSentCount(1);

    expect($campaign->fresh()->sent_count)->toBe(2);
});

// ─── Numbers nobody can send to ──────────────────────────────────────────────

describe('numbers on no network', function () {
    /*
     * One in ten numbers on the first campaign was a placeholder typed at the
     * till: 098…, 087…. They were counted, priced and sent to Hubtel.
     */
    it('leaves them off the send and says how many', function () {
        pauseAudience();

        Order::factory()->create([
            'customer_id' => null,
            'contact_name' => 'Walk in',
            'contact_phone' => '+233987654321',
            'status' => 'completed',
            'created_at' => now()->subDay(),
        ]);

        $campaign = pauseDraft();

        $this->actingAs(pauseAdmin(), 'sanctum')
            ->getJson("/v1/admin/campaigns/{$campaign->id}/preview")
            ->assertSuccessful()
            ->assertJsonPath('data.recipient_count', 6)
            ->assertJsonPath('data.effective_recipient_count', 5)
            ->assertJsonPath('data.left_out_count', 1);

        Http::fake(['*' => hubtelAccepts()]);

        $this->actingAs(pauseAdmin(), 'sanctum')->postJson("/v1/admin/campaigns/{$campaign->id}/send");

        expect(numbersSentToHubtel())->not->toContain('233987654321')
            ->and($campaign->fresh()->recipient_count)->toBe(5)
            ->and($campaign->fresh()->status)->toBe(CampaignStatus::Sent);
    });

    it('shows them as their own slice of the list', function () {
        pauseAudience();

        Order::factory()->create([
            'customer_id' => null,
            'contact_phone' => '+233987654321',
            'status' => 'completed',
            'created_at' => now()->subDay(),
        ]);

        $networks = $this->actingAs(pauseAdmin(), 'sanctum')
            ->getJson('/v1/admin/campaigns/segments')
            ->assertSuccessful()
            ->json('data.networks');

        $byValue = collect($networks)->pluck('count', 'value');

        expect($byValue['mtn'])->toBe(5)
            ->and($byValue['other'])->toBe(1)
            ->and($byValue['telecel'])->toBe(0);
    });
});

// ─── The report the charts read ──────────────────────────────────────────────

it('reports where the whole list stands, with the reason people were missed', function () {
    pauseAudience();

    Http::fakeSequence()
        ->pushResponse(hubtelAccepts('b1'))
        ->pushResponse(hubtelOutOfCredit());

    $campaign = pauseDraft();

    $this->actingAs(pauseAdmin(), 'sanctum')->postJson("/v1/admin/campaigns/{$campaign->id}/send");

    $this->actingAs(pauseAdmin(), 'sanctum')
        ->getJson("/v1/admin/campaigns/{$campaign->id}/report")
        ->assertSuccessful()
        ->assertJsonPath('data.reach.tracked', true)
        ->assertJsonPath('data.reach.audience', 5)
        ->assertJsonPath('data.reach.accepted', 2)
        ->assertJsonPath('data.reach.waiting', 3)
        ->assertJsonPath('data.reach.refused', 0)
        ->assertJsonPath('data.reach.resumable', 3)
        ->assertJsonPath('data.reasons.0.reason', 'no_credit')
        ->assertJsonPath('data.reasons.0.count', 2)
        ->assertJsonPath('data.networks.0.value', 'mtn')
        ->assertJsonPath('data.networks.0.sent_to', 5)
        ->assertJsonPath('data.networks.0.accepted', 2);
});

// ─── Putting the first campaign right ────────────────────────────────────────

describe('rebuilding the list of a campaign sent before the list was kept', function () {
    /**
     * "New Month" in miniature: one accepted, two refused for credit and filed
     * as unknown, one placeholder number, status Sent.
     */
    function newMonthInMiniature(): Campaign
    {
        $campaign = Campaign::factory()->sent()->create([
            'created_by_user_id' => pauseAdmin()->id,
            'approved_by_user_id' => pauseAdmin()->id,
            'recipient_count' => 4,
            'sent_count' => 1,
            'failed_count' => 3,
            'batch_ids' => ['old-batch'],
        ]);

        $refusal = '{"batchId":null,"status":12,"data":null}';

        foreach ([
            ['233241111111', true, null, null],
            ['233242222222', false, 'unknown', $refusal],
            ['233243333333', false, 'unknown', $refusal],
            ['233987654321', false, 'unknown', $refusal],
        ] as [$recipient, $succeeded, $reason, $error]) {
            SmsDeliveryAttempt::create([
                'notification' => 'campaign',
                'is_campaign' => true,
                'campaign_id' => $campaign->id,
                'recipient' => $recipient,
                'succeeded' => $succeeded,
                'failure_reason' => $reason,
                'error_message' => $error,
                'created_at' => now()->subHours(2),
            ]);
        }

        return $campaign;
    }

    it('reads the stored refusals again and corrects the status', function () {
        $campaign = newMonthInMiniature();

        $this->artisan('campaigns:backfill-recipients', ['campaign' => $campaign->id])
            ->assertSuccessful();

        expect($campaign->fresh()->status)->toBe(CampaignStatus::PartlySent)
            ->and(statesOf($campaign))->toBe(['accepted' => 1, 'refused' => 3]);

        $reasons = CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('state', CampaignRecipientState::Refused->value)
            ->pluck('failure_reason', 'phone')
            ->map(fn (SmsFailureReason $r) => $r->value)
            ->all();

        expect($reasons)->toBe([
            '+233242222222' => 'no_credit',
            '+233243333333' => 'no_credit',
            '+233987654321' => 'invalid_recipient',
        ]);
    });

    it('changes nothing on a dry run', function () {
        $campaign = newMonthInMiniature();

        $this->artisan('campaigns:backfill-recipients', ['campaign' => $campaign->id, '--dry' => true])
            ->assertSuccessful();

        expect(CampaignRecipient::count())->toBe(0)
            ->and($campaign->fresh()->status)->toBe(CampaignStatus::Sent);
    });

    it('then sends to the two who were missed, and to nobody else', function () {
        $campaign = newMonthInMiniature();

        $this->artisan('campaigns:backfill-recipients', ['campaign' => $campaign->id]);

        Http::fake(['*' => hubtelAccepts('new-batch')]);

        $this->actingAs(pauseAdmin(), 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/resume")
            ->assertSuccessful();

        $sentTo = numbersSentToHubtel();
        sort($sentTo);

        $campaign->refresh();

        // Not the one who already has it, and not the placeholder.
        expect($sentTo)->toBe(['233242222222', '233243333333'])
            ->and($campaign->sent_count)->toBe(3)
            ->and($campaign->failed_count)->toBe(1)
            ->and($campaign->status)->toBe(CampaignStatus::PartlySent)
            ->and($campaign->batch_ids)->toBe(['old-batch', 'new-batch']);

        $this->actingAs(pauseAdmin(), 'sanctum')
            ->getJson("/v1/admin/campaigns/{$campaign->id}")
            ->assertJsonPath('data.can_resume', false)
            ->assertJsonPath('data.resumable_count', 0);

        $this->actingAs(pauseAdmin(), 'sanctum')
            ->getJson("/v1/admin/campaigns/{$campaign->id}/report")
            ->assertJsonPath('data.reach.accepted', 3)
            ->assertJsonPath('data.reach.not_mobile', 1)
            ->assertJsonPath('data.reach.refused', 0);
    });

    it('says so when the attempt log has already been cleared', function () {
        $campaign = Campaign::factory()->sent()->create([
            'created_by_user_id' => pauseAdmin()->id,
            'recipient_count' => 4,
            'sent_count' => 1,
            'failed_count' => 3,
        ]);

        $this->artisan('campaigns:backfill-recipients', ['campaign' => $campaign->id])
            ->assertFailed();
    });
});

// ─── The delivery curve ──────────────────────────────────────────────────────

/*
 * The curve said nobody had been delivered to in the first hour, on a campaign
 * where most of them had. It read updated_at, and the poller moves updated_at on
 * every row every fifteen minutes.
 */
it('keeps the moment a message was first delivered, however many polls follow', function () {
    $campaign = Campaign::factory()->sent()->create([
        'created_by_user_id' => pauseAdmin()->id,
        'recipient_count' => 2,
        'sent_count' => 2,
        'batch_ids' => ['b1'],
        'started_at' => now(),
        'completed_at' => now(),
    ]);

    $poller = app(\App\Services\Campaigns\CampaignDeliveryPoller::class);

    $status = fn (string $second) => Http::response(['batchId' => 'b1', 'data' => [
        ['to' => '233241111111', 'status' => 'Delivered', 'rate' => 0.0243],
        ['to' => '233242222222', 'status' => $second, 'rate' => 0.0243],
    ]], 200);

    Http::fakeSequence()
        ->pushResponse($status('Sent'))
        ->pushResponse($status('Sent'))
        ->pushResponse($status('Delivered'));

    // Twenty minutes in: one has arrived.
    $this->travel(20)->minutes();
    $poller->poll($campaign);

    // Three hours in: nothing new, but every row is touched again.
    $this->travel(160)->minutes();
    $poller->poll($campaign);

    // Ten hours in: the second one arrives.
    $this->travel(7)->hours();
    $poller->poll($campaign);

    $this->travel(40)->hours();

    $curve = collect(app(\App\Services\Campaigns\CampaignDeliveryReport::class)->curve($campaign->fresh()))
        ->pluck('delivered', 'hour')
        ->all();

    expect($curve)->toBe([1 => 1, 6 => 1, 24 => 2, 48 => 2]);
});

it('says which marks on the curve have not been reached yet', function () {
    $campaign = Campaign::factory()->sent()->create([
        'created_by_user_id' => pauseAdmin()->id,
        'started_at' => now()->subHours(7),
    ]);

    $reached = collect(app(\App\Services\Campaigns\CampaignDeliveryReport::class)->curve($campaign))
        ->pluck('reached', 'hour')
        ->all();

    expect($reached)->toBe([1 => true, 6 => true, 24 => false, 48 => false]);
});
