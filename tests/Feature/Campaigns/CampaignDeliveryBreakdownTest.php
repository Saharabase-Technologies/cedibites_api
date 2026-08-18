<?php

use App\Enums\CampaignStatus;
use App\Enums\DeliveryOutcome;
use App\Models\Campaign;
use App\Models\CampaignDelivery;
use App\Models\User;
use App\Services\Campaigns\CampaignDeliveryPoller;
use App\Services\Campaigns\CampaignDeliveryReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    User::query()->forceDelete();

    SpatieRole::findOrCreate('admin', 'api')
        ->givePermissionTo(SpatiePermission::findOrCreate('manage_campaigns', 'api'));

    config([
        'services.hubtel.client_id' => 'test-id',
        'services.hubtel.client_secret' => 'test-secret',
        'campaigns.delivery_poll_hours' => 48,
    ]);
});

function breakdownAdmin(): User
{
    $existing = User::where('phone', '+233200000041')->first();

    if ($existing) {
        return $existing;
    }

    $user = User::factory()->create(['phone' => '+233200000041']);
    $user->assignRole('admin');

    return $user;
}

function campaignWithBatch(array $attributes = []): Campaign
{
    return Campaign::factory()->create([
        'created_by_user_id' => breakdownAdmin()->id,
        'status' => CampaignStatus::Sent,
        'batch_ids' => ['batch-1'],
        'started_at' => now()->subHour(),
        'sent_count' => 4,
        ...$attributes,
    ]);
}

function fakeBatchStatus(array $messages): void
{
    Http::fake(['*' => Http::response(['batchId' => 'batch-1', 'data' => $messages], 200)]);
}

// ─── Reading a status ────────────────────────────────────────────────────────

it('reads Hubtel wording into an outcome', function (string $status, DeliveryOutcome $expected) {
    expect(DeliveryOutcome::fromProviderStatus($status))->toBe($expected);
})->with([
    'delivered' => ['Delivered', DeliveryOutcome::Delivered],
    'lowercase' => ['delivered', DeliveryOutcome::Delivered],
    'failed' => ['Failed', DeliveryOutcome::Failed],
    'rejected' => ['Rejected', DeliveryOutcome::Failed],
    'undelivered is not delivered' => ['Undelivered', DeliveryOutcome::Failed],
    'unknown subscriber' => ['Unknown subscriber', DeliveryOutcome::Failed],
    // The handset was off for the whole validity window. Not a bad number.
    'expired' => ['Expired', DeliveryOutcome::Unconfirmed],
    'pending' => ['Pending', DeliveryOutcome::Pending],
    'submitted' => ['Submitted', DeliveryOutcome::Pending],
    // A wording nobody anticipated must never be guessed into Failed.
    'something new' => ['Quantum superposition', DeliveryOutcome::Pending],
    'empty' => ['', DeliveryOutcome::Pending],
]);

// ─── Recording ───────────────────────────────────────────────────────────────

it('records one row per recipient, with the provider wording kept', function () {
    $campaign = campaignWithBatch();

    fakeBatchStatus([
        ['to' => '233241111111', 'status' => 'Delivered', 'rate' => 0.0243],
        ['to' => '233242222222', 'status' => 'Rejected', 'rate' => 0.0243],
    ]);

    app(CampaignDeliveryPoller::class)->poll($campaign);

    $delivered = CampaignDelivery::where('phone', '+233241111111')->first();
    $rejected = CampaignDelivery::where('phone', '+233242222222')->first();

    expect($delivered->outcome)->toBe(DeliveryOutcome::Delivered)
        ->and($rejected->outcome)->toBe(DeliveryOutcome::Failed)
        // The word Hubtel used, not our reading of it — so a wording change
        // shows up as an unclassified status rather than a silent shift.
        ->and($rejected->provider_status)->toBe('Rejected');
});

it('normalises the recipient so a row can be matched to a contact', function () {
    $campaign = campaignWithBatch();

    // Hubtel answers 233…; everything on our side is +233….
    fakeBatchStatus([['to' => '233241111111', 'status' => 'Delivered', 'rate' => 0.0243]]);

    app(CampaignDeliveryPoller::class)->poll($campaign);

    expect(CampaignDelivery::first()->phone)->toBe('+233241111111');
});

it('settles rather than accumulates when polled repeatedly', function () {
    $campaign = campaignWithBatch();

    // A sequence, not two fake() calls: Http::fake() registers stubs and the
    // FIRST match wins, so calling it twice would answer "Pending" both times
    // and the test would be checking nothing.
    Http::fake([
        '*' => Http::sequence()
            ->push(['batchId' => 'batch-1', 'data' => [['to' => '233241111111', 'status' => 'Pending', 'rate' => 0.0243]]], 200)
            ->push(['batchId' => 'batch-1', 'data' => [['to' => '233241111111', 'status' => 'Delivered', 'rate' => 0.0243]]], 200),
    ]);

    app(CampaignDeliveryPoller::class)->poll($campaign);
    app(CampaignDeliveryPoller::class)->poll($campaign);

    // The batch endpoint returns the whole list every time; without the unique
    // key two days of polling would leave 192 copies of this campaign.
    expect(CampaignDelivery::count())->toBe(1)
        ->and(CampaignDelivery::first()->outcome)->toBe(DeliveryOutcome::Delivered);
});

it('writes nothing when a batch cannot be read', function () {
    $campaign = campaignWithBatch();
    Http::fake(['*' => Http::response([], 500)]);

    app(CampaignDeliveryPoller::class)->poll($campaign);

    expect(CampaignDelivery::count())->toBe(0);
});

// ─── The breakdown ───────────────────────────────────────────────────────────

it('separates failures from messages that were never confirmed', function () {
    /*
     * The distinction the whole feature exists for. A dead number and a handset
     * that was switched off both show as "not delivered" and call for opposite
     * responses — retire the first, try the second again tomorrow.
     */
    $campaign = campaignWithBatch(['sent_count' => 4]);

    fakeBatchStatus([
        ['to' => '233241111111', 'status' => 'Delivered', 'rate' => 0.0243],
        ['to' => '233242222222', 'status' => 'Delivered', 'rate' => 0.0243],
        ['to' => '233243333333', 'status' => 'Failed', 'rate' => 0.0243],
        ['to' => '233244444444', 'status' => 'Pending', 'rate' => 0.0243],
    ]);

    app(CampaignDeliveryPoller::class)->poll($campaign);

    $summary = app(CampaignDeliveryReport::class)->summarise($campaign->fresh());

    expect($summary['delivered'])->toBe(2)
        ->and($summary['failed'])->toBe(1)
        ->and($summary['pending'])->toBe(1)
        ->and($summary['unconfirmed'])->toBe(0)
        ->and($summary['delivery_rate'])->toBe(50.0)
        ->and($summary['is_final'])->toBeFalse();
});

it('turns pending into unconfirmed once we have stopped asking', function () {
    // Nothing is "still trying" when nothing is trying. A campaign past the
    // polling window must not report messages as in flight.
    $campaign = campaignWithBatch([
        'sent_count' => 2,
        'started_at' => now()->subHours(72),
    ]);

    CampaignDelivery::create([
        'campaign_id' => $campaign->id, 'phone' => '+233241111111',
        'outcome' => DeliveryOutcome::Delivered->value,
    ]);
    CampaignDelivery::create([
        'campaign_id' => $campaign->id, 'phone' => '+233242222222',
        'outcome' => DeliveryOutcome::Pending->value,
    ]);

    $summary = app(CampaignDeliveryReport::class)->summarise($campaign);

    expect($summary['pending'])->toBe(0)
        ->and($summary['unconfirmed'])->toBe(1)
        ->and($summary['is_final'])->toBeTrue();
});

it('counts accepted messages it holds no status for as unknown, not as failures', function () {
    // A gap in our own bookkeeping is not a statement about the recipient.
    $campaign = campaignWithBatch(['sent_count' => 10]);

    CampaignDelivery::create([
        'campaign_id' => $campaign->id, 'phone' => '+233241111111',
        'outcome' => DeliveryOutcome::Delivered->value,
    ]);

    $summary = app(CampaignDeliveryReport::class)->summarise($campaign);

    expect($summary['unknown'])->toBe(9)
        ->and($summary['failed'])->toBe(0);
});

// ─── The endpoint ────────────────────────────────────────────────────────────

it('lists the ones that did not arrive', function () {
    $campaign = campaignWithBatch(['sent_count' => 3]);

    foreach ([
        ['+233241111111', DeliveryOutcome::Delivered],
        ['+233242222222', DeliveryOutcome::Failed],
        ['+233243333333', DeliveryOutcome::Unconfirmed],
    ] as [$phone, $outcome]) {
        CampaignDelivery::create([
            'campaign_id' => $campaign->id, 'phone' => $phone, 'outcome' => $outcome->value,
        ]);
    }

    $this->actingAs(breakdownAdmin(), 'sanctum')
        ->getJson("/v1/admin/campaigns/{$campaign->id}/deliveries?outcome=not_delivered")
        ->assertOk()
        ->assertJsonCount(2, 'data.deliveries.data')
        ->assertJsonPath('data.summary.delivered', 1);

    $this->actingAs(breakdownAdmin(), 'sanctum')
        ->getJson("/v1/admin/campaigns/{$campaign->id}/deliveries?outcome=failed")
        ->assertOk()
        ->assertJsonCount(1, 'data.deliveries.data')
        ->assertJsonPath('data.deliveries.data.0.phone', '+233242222222');
});

it('keeps the breakdown behind manage_campaigns', function () {
    $campaign = campaignWithBatch();
    $nobody = User::factory()->create(['phone' => '+233200000042']);

    $this->actingAs($nobody, 'sanctum')
        ->getJson("/v1/admin/campaigns/{$campaign->id}/deliveries")
        ->assertForbidden();
});
