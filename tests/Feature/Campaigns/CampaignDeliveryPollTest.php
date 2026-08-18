<?php

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Campaigns\CampaignDeliveryPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.hubtel.client_id' => 'test-id',
        'services.hubtel.client_secret' => 'test-secret',
        'services.hubtel.sms_base_url' => 'https://sms.hubtel.com/v1/messages',
    ]);

    $this->poller = app(CampaignDeliveryPoller::class);
});

function sentCampaign(array $attributes = []): Campaign
{
    return Campaign::factory()->create([
        'status' => CampaignStatus::Sent,
        'started_at' => now()->subMinutes(20),
        'recipient_count' => 3,
        'sent_count' => 3,
        'batch_ids' => ['batch-1'],
        'created_by_user_id' => User::factory(),
        ...$attributes,
    ]);
}

/** The shape the live endpoint returns, captured from the beta account. */
function batchBody(array $messages): array
{
    return ['batchId' => 'batch-1', 'data' => $messages];
}

// ─── The number the higher-ups ask for ───────────────────────────────────────

/*
 * The send response carries no rate at all, and the per-message lookup 404s.
 * `GET /messages/batch/{batchId}` is the only endpoint on this account that
 * says what a campaign actually cost — without it every figure on screen is a
 * projection from a configured guess.
 */
it('reads the real cost back from the provider', function () {
    Http::fake(['*/batch/batch-1' => Http::response(batchBody([
        ['messageId' => 'm1', 'status' => 'Delivered', 'rate' => 0.0243, 'to' => '233241111111'],
        ['messageId' => 'm2', 'status' => 'Delivered', 'rate' => 0.0243, 'to' => '233242222222'],
        ['messageId' => 'm3', 'status' => 'Submitted', 'rate' => 0.0243, 'to' => '233243333333'],
    ]))]);

    $campaign = sentCampaign();

    expect($this->poller->poll($campaign))->toBeTrue();

    expect((float) $campaign->fresh()->actual_cost)->toBe(0.0729)
        ->and($campaign->fresh()->delivery_checked_at)->not->toBeNull();
});

/*
 * Accepted and delivered are different numbers. A campaign can be taken in full
 * and land on two thirds of the handsets, and sent_count would never show it.
 */
it('counts what arrived separately from what was accepted', function () {
    Http::fake(['*/batch/batch-1' => Http::response(batchBody([
        ['messageId' => 'm1', 'status' => 'Delivered', 'rate' => 0.0243],
        ['messageId' => 'm2', 'status' => 'Delivered', 'rate' => 0.0243],
        ['messageId' => 'm3', 'status' => 'Failed', 'rate' => 0.0243],
    ]))]);

    $campaign = sentCampaign();
    $this->poller->poll($campaign);

    expect($campaign->fresh()->delivered_count)->toBe(2)
        // Untouched — it means "Hubtel took this from us", and it still did.
        ->and($campaign->fresh()->sent_count)->toBe(3);
});

/* Charged whether or not it landed. The bill is the bill. */
it('bills for messages that did not arrive', function () {
    Http::fake(['*/batch/batch-1' => Http::response(batchBody([
        ['messageId' => 'm1', 'status' => 'Failed', 'rate' => 0.0243],
    ]))]);

    $campaign = sentCampaign();
    $this->poller->poll($campaign);

    expect((float) $campaign->fresh()->actual_cost)->toBe(0.0243)
        ->and($campaign->fresh()->delivered_count)->toBe(0);
});

/* The field is prose, not an enum, and casing is one provider change away. */
it('does not care how the provider capitalises "delivered"', function () {
    Http::fake(['*/batch/batch-1' => Http::response(batchBody([
        ['messageId' => 'm1', 'status' => 'DELIVERED', 'rate' => 0.01],
        ['messageId' => 'm2', 'status' => 'delivered', 'rate' => 0.01],
    ]))]);

    $campaign = sentCampaign();
    $this->poller->poll($campaign);

    expect($campaign->fresh()->delivered_count)->toBe(2);
});

// ─── Refusing to guess ───────────────────────────────────────────────────────

/*
 * A partial answer is worse than none. Summing only the batches that could be
 * reached reports a cost lower than the truth, and it does not look like a gap —
 * it looks like a measurement.
 */
it('writes nothing when one batch of several cannot be read', function () {
    Http::fake([
        '*/batch/batch-1' => Http::response(batchBody([
            ['messageId' => 'm1', 'status' => 'Delivered', 'rate' => 0.0243],
        ])),
        '*/batch/batch-2' => Http::response(null, 500),
    ]);

    $campaign = sentCampaign(['batch_ids' => ['batch-1', 'batch-2']]);

    expect($this->poller->poll($campaign))->toBeFalse();

    expect($campaign->fresh()->actual_cost)->toBeNull()
        ->and($campaign->fresh()->delivery_checked_at)->toBeNull();
});

it('leaves a previously measured cost alone when a later poll fails', function () {
    Http::fake(['*' => Http::response(null, 500)]);

    $campaign = sentCampaign(['actual_cost' => 1.23]);

    $this->poller->poll($campaign);

    expect((float) $campaign->fresh()->actual_cost)->toBe(1.23);
});

it('skips a campaign that never recorded a batch', function () {
    Http::fake();

    expect($this->poller->poll(sentCampaign(['batch_ids' => null])))->toBeFalse();

    Http::assertNothingSent();
});

// ─── Which campaigns get asked about ─────────────────────────────────────────

describe('what the scheduled run picks up', function () {
    it('polls campaigns sent inside the window', function () {
        Http::fake(['*' => Http::response(batchBody([
            ['messageId' => 'm1', 'status' => 'Delivered', 'rate' => 0.02],
        ]))]);

        sentCampaign(['started_at' => now()->subHour()]);

        expect($this->poller->pollRecent(48))->toBe(1);
    });

    /* Delivery statuses settle within hours. Anything older is history. */
    it('leaves older campaigns alone', function () {
        Http::fake();

        sentCampaign(['started_at' => now()->subDays(10)]);

        expect($this->poller->pollRecent(48))->toBe(0);
        Http::assertNothingSent();
    });

    it('ignores drafts, which have nothing to ask about', function () {
        Http::fake();

        Campaign::factory()->create([
            'status' => CampaignStatus::Draft,
            'batch_ids' => ['batch-1'],
            'started_at' => now(),
            'created_by_user_id' => User::factory(),
        ]);

        expect($this->poller->pollRecent(48))->toBe(0);
        Http::assertNothingSent();
    });
});
