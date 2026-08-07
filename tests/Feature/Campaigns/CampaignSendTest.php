<?php

use App\Enums\CampaignSegment;
use App\Enums\CampaignStatus;
use App\Jobs\SendCampaignChunk;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SmsDeliveryAttempt;
use App\Models\User;
use App\Services\Campaigns\CampaignSender;
use App\Services\SmsHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

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
        'campaigns.recipient_cap' => 2000,
        'campaigns.chunk_size' => 1000,
        'campaigns.inter_batch_delay_seconds' => 0,
        'campaigns.send_window.enabled' => false,
        'campaigns.estimated_rate_per_segment' => 0.05,
        'services.hubtel.client_id' => 'test-id',
        'services.hubtel.client_secret' => 'test-secret',
    ]);
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

/** Idempotent — several assertions in one test need the same admin, not several. */
function campaignAdmin(): User
{
    $existing = User::where('phone', '+233200000001')->first();

    if ($existing) {
        return $existing;
    }

    $user = User::factory()->create(['phone' => '+233200000001']);
    $user->assignRole('admin');

    return $user;
}

/**
 * Somebody who ordered `$daysAgo` days ago, so the segments have people in them.
 *
 * Deliberately a guest order with no customer_id. A registered customer would
 * put a second, random phone into the audience via their user record, and every
 * recipient count in this file would drift with the faker seed.
 */
function orderingCustomer(string $phone, int $daysAgo = 1, int $orders = 1): void
{
    for ($i = 0; $i < $orders; $i++) {
        Order::factory()->create([
            'customer_id' => null,
            'contact_name' => 'Ama',
            'contact_phone' => $phone,
            'status' => 'completed',
            'created_at' => now()->subDays($daysAgo),
        ]);
    }
}

// ─── The rails ───────────────────────────────────────────────────────────────

describe('the rails that stop an accidental blast', function () {
    /*
     * The audience is 28,000+ and this console is new. A mistyped segment must
     * not be able to become a real send — the cap is what makes the difference
     * between a mistake and an invoice.
     */
    it('refuses a segment larger than the cap', function () {
        config(['campaigns.recipient_cap' => 2]);

        orderingCustomer('+233241111111');
        orderingCustomer('+233242222222');
        orderingCustomer('+233243333333');

        $campaign = Campaign::factory()->create(['created_by_user_id' => campaignAdmin()->id]);

        $this->actingAs(campaignAdmin(), 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/send")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'over the 2 limit'));

        expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft);
    });

    /*
     * Seed mode is how the whole mechanism gets proven for a few cedis instead
     * of four figures. The chosen segment is still resolved and still reported,
     * so the operator sees the real reach next to the handful actually messaged.
     */
    it('sends only to the seed list when seed mode is on, whatever the segment', function () {
        Bus::fake();

        config([
            'campaigns.seed_mode' => true,
            'campaigns.seed_list' => ['233200000001', '233200000002'],
        ]);

        orderingCustomer('+233241111111');
        orderingCustomer('+233242222222');

        $campaign = Campaign::factory()->create(['created_by_user_id' => campaignAdmin()->id]);

        $this->actingAs(campaignAdmin(), 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/send")
            ->assertSuccessful();

        expect($campaign->fresh()->recipient_count)->toBe(2);

        Bus::assertDispatched(
            SendCampaignChunk::class,
            fn (SendCampaignChunk $job) => $job->recipients === ['233200000001', '233200000002'],
        );
    });

    it('reports the real segment size alongside the seed list in the preview', function () {
        config([
            'campaigns.seed_mode' => true,
            'campaigns.seed_list' => ['233200000001'],
        ]);

        orderingCustomer('+233241111111');
        orderingCustomer('+233242222222');
        orderingCustomer('+233243333333');

        $campaign = Campaign::factory()->create(['created_by_user_id' => campaignAdmin()->id]);

        $this->actingAs(campaignAdmin(), 'sanctum')
            ->getJson("/v1/admin/campaigns/{$campaign->id}/preview")
            ->assertSuccessful()
            // What the segment holds …
            ->assertJsonPath('data.recipient_count', 3)
            // … and what is actually about to be messaged.
            ->assertJsonPath('data.effective_recipient_count', 1)
            ->assertJsonPath('data.seed_mode', true);
    });

    /*
     * Enforced in the sender rather than the controller, because a scheduled
     * send never passes through a controller and a guard that only runs on the
     * button is not a guard.
     */
    it('refuses to send outside the window', function () {
        config([
            'campaigns.send_window.enabled' => true,
            'campaigns.send_window.start_hour' => 8,
            'campaigns.send_window.end_hour' => 19,
            'campaigns.send_window.blocked_days' => [7],
        ]);

        // A Wednesday at 03:00.
        $this->travelTo(now()->setDate(2026, 8, 5)->setTime(3, 0));

        orderingCustomer('+233241111111');
        $campaign = Campaign::factory()->create(['created_by_user_id' => campaignAdmin()->id]);

        $this->actingAs(campaignAdmin(), 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/send")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'between 8:00 and 19:00'));
    });

    it('refuses to send on a Sunday', function () {
        config([
            'campaigns.send_window.enabled' => true,
            'campaigns.send_window.blocked_days' => [7],
        ]);

        // Sunday 2 August 2026, midday.
        $this->travelTo(now()->setDate(2026, 8, 2)->setTime(12, 0));

        orderingCustomer('+233241111111');
        $campaign = Campaign::factory()->create(['created_by_user_id' => campaignAdmin()->id]);

        $this->actingAs(campaignAdmin(), 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/send")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Sunday'));
    });

    /*
     * A conditional UPDATE is the claim. Two operators pressing approve at the
     * same moment must not both get through — that is 28,000 people texted
     * twice, and no amount of read-then-write checking prevents it.
     */
    it('cannot be sent twice', function () {
        Bus::fake();
        orderingCustomer('+233241111111');

        $campaign = Campaign::factory()->create(['created_by_user_id' => campaignAdmin()->id]);
        $admin = campaignAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/send")
            ->assertSuccessful();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/send")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This campaign has already been sent.');
    });

    it('refuses a segment with nobody in it', function () {
        $campaign = Campaign::factory()->create([
            'segment' => CampaignSegment::Churned,
            'created_by_user_id' => campaignAdmin()->id,
        ]);

        $this->actingAs(campaignAdmin(), 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/send")
            ->assertStatus(422);
    });
});

// ─── Chunking and accounting ─────────────────────────────────────────────────

describe('chunking', function () {
    it('breaks the audience into chunks of the configured size', function () {
        Bus::fake();
        config(['campaigns.chunk_size' => 2, 'campaigns.recipient_cap' => 100]);

        orderingCustomer('+233241111111');
        orderingCustomer('+233242222222');
        orderingCustomer('+233243333333');

        $campaign = Campaign::factory()->create(['created_by_user_id' => campaignAdmin()->id]);

        $this->actingAs(campaignAdmin(), 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/send")
            ->assertSuccessful();

        Bus::assertDispatchedTimes(SendCampaignChunk::class, 2);
        expect($campaign->fresh()->recipient_count)->toBe(3);
    });

    /*
     * Hubtel wants 233XXXXXXXXX. The audience is stored as +233…, which
     * HubtelSmsService rejects outright — so an unconverted number would be
     * recorded as a failure for every recipient rather than sent.
     */
    it('converts +233 numbers to the format Hubtel accepts', function () {
        Bus::fake();
        orderingCustomer('+233241111111');

        $campaign = Campaign::factory()->create(['created_by_user_id' => campaignAdmin()->id]);

        $this->actingAs(campaignAdmin(), 'sanctum')
            ->postJson("/v1/admin/campaigns/{$campaign->id}/send")
            ->assertSuccessful();

        Bus::assertDispatched(
            SendCampaignChunk::class,
            fn (SendCampaignChunk $job) => $job->recipients === ['233241111111'],
        );
    });
});

describe('the permanent record', function () {
    /*
     * The trap in this phase. sms_delivery_attempts is pruned by
     * sms:health-check, so reporting that read its figures from there would
     * watch campaign history evaporate at the retention boundary — a number
     * shown to the board last month returning something different this month.
     */
    it('keeps its totals after the attempt rows are pruned', function () {
        $campaign = Campaign::factory()->sending()->create([
            'created_by_user_id' => campaignAdmin()->id,
            'recipient_count' => 3,
        ]);

        app(CampaignSender::class)->recordChunkResult($campaign->id, sent: 2, failed: 1, cost: 0.15);

        SmsDeliveryAttempt::create([
            'recipient' => '233241111111',
            'succeeded' => true,
            'is_campaign' => true,
            'campaign_id' => $campaign->id,
            'created_at' => now()->subDays(400),
        ]);

        SmsDeliveryAttempt::where('created_at', '<', now()->subDays(90))->delete();

        expect(SmsDeliveryAttempt::where('campaign_id', $campaign->id)->count())->toBe(0)
            ->and($campaign->fresh()->sent_count)->toBe(2)
            ->and($campaign->fresh()->failed_count)->toBe(1)
            ->and((float) $campaign->fresh()->actual_cost)->toBe(0.15);
    });

    it('completes once every recipient is accounted for', function () {
        $campaign = Campaign::factory()->sending()->create([
            'created_by_user_id' => campaignAdmin()->id,
            'recipient_count' => 4,
        ]);

        $sender = app(CampaignSender::class);

        $sender->recordChunkResult($campaign->id, sent: 2, failed: 0);
        expect($campaign->fresh()->status)->toBe(CampaignStatus::Sending);

        $sender->recordChunkResult($campaign->id, sent: 2, failed: 0);
        expect($campaign->fresh()->status)->toBe(CampaignStatus::Sent)
            ->and($campaign->fresh()->completed_at)->not->toBeNull();
    });

    /*
     * A campaign that reached most of the list is a campaign that happened.
     * Calling it "failed" because one chunk of a thousand was rejected would
     * hide from the report that 27,000 people got the message.
     */
    it('is only failed when nothing at all got through', function () {
        $partial = Campaign::factory()->sending()->create([
            'created_by_user_id' => campaignAdmin()->id, 'recipient_count' => 2,
        ]);
        $total = Campaign::factory()->sending()->create([
            'created_by_user_id' => campaignAdmin()->id, 'recipient_count' => 2,
        ]);

        $sender = app(CampaignSender::class);
        $sender->recordChunkResult($partial->id, sent: 1, failed: 1);
        $sender->recordChunkResult($total->id, sent: 0, failed: 2);

        expect($partial->fresh()->status)->toBe(CampaignStatus::Sent)
            ->and($total->fresh()->status)->toBe(CampaignStatus::Failed);
    });

    /*
     * Unmeasured must not read as free. GHS 0.00 on a report says the campaign
     * cost nothing; null says we do not know yet, which is the truth until
     * Hubtel returns a rate.
     */
    it('leaves the actual cost null when the provider did not say', function () {
        $campaign = Campaign::factory()->sending()->create([
            'created_by_user_id' => campaignAdmin()->id, 'recipient_count' => 1,
        ]);

        app(CampaignSender::class)->recordChunkResult($campaign->id, sent: 1, failed: 0, cost: null);

        expect($campaign->fresh()->actual_cost)->toBeNull();
    });
});

// ─── Health signal isolation ─────────────────────────────────────────────────

/*
 * The exclusion has to hold in both directions, and the second is the easier one
 * to get wrong. Phase 0 built it; this proves a real campaign send still
 * respects it end to end.
 */
it('does not touch the SMS health verdict when a whole campaign fails', function () {
    Http::fake([
        '*' => Http::response(['status' => 4109, 'statusDescription' => 'Payment required on account'], 200),
    ]);

    $campaign = Campaign::factory()->sending()->create([
        'created_by_user_id' => campaignAdmin()->id, 'recipient_count' => 3,
    ]);

    (new SendCampaignChunk($campaign->id, ['233241111111', '233242222222', '233243333333'], 'Hello'))
        ->handle(app(\App\Services\HubtelSmsService::class), app(CampaignSender::class));

    // Every recipient recorded as failed …
    expect(SmsDeliveryAttempt::where('campaign_id', $campaign->id)->where('succeeded', false)->count())->toBe(3)
        ->and($campaign->fresh()->failed_count)->toBe(3);

    // … and the pipe customers depend on still reads as healthy.
    expect(app(SmsHealthService::class)->check()['status'])->not->toBe('critical');
});

// ─── Access ──────────────────────────────────────────────────────────────────

it('refuses a manager', function () {
    $manager = User::factory()->create(['phone' => '+233209999999']);
    $manager->assignRole('manager');

    $this->actingAs($manager, 'sanctum')
        ->getJson('/v1/admin/campaigns')
        ->assertForbidden();
});

it('rejects unauthenticated requests', function () {
    $this->getJson('/v1/admin/campaigns')->assertUnauthorized();
});
