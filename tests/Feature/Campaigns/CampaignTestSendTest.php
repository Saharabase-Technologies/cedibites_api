<?php

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Sending yourself a copy before you send it to everybody.
 *
 * The point of the endpoint is that it is a real text to a real handset carrying
 * the campaign's exact words. Most of what is worth asserting here is about what
 * it does NOT do to the campaign it copied.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    User::query()->forceDelete();

    SpatieRole::findOrCreate('admin', 'api')
        ->givePermissionTo(SpatiePermission::findOrCreate('manage_campaigns', 'api'));
    SpatieRole::findOrCreate('manager', 'api');

    config([
        // ON, so these tests prove the test send ignores it. That is the whole
        // reason the endpoint exists rather than reusing seed mode.
        'campaigns.seed_mode' => true,
        'campaigns.seed_list' => ['233209999999'],
        'services.hubtel.client_id' => 'test-id',
        'services.hubtel.client_secret' => 'test-secret',
        'campaigns.estimated_rate_per_segment' => 0.0243,
    ]);
});

/** Idempotent, because a test that sends twice needs the same admin, not two of them. */
function testSendAdmin(): User
{
    $existing = User::where('phone', '+233200000031')->first();

    if ($existing) {
        return $existing;
    }

    $user = User::factory()->create(['phone' => '+233200000031']);
    $user->assignRole('admin');

    return $user;
}

function hubtelAcceptsTest(): void
{
    Http::fake(['*' => Http::response(['status' => 0, 'messageId' => 'test-1'], 201)]);
}

it('texts the campaign message, character for character, to the number typed', function () {
    hubtelAcceptsTest();

    $campaign = Campaign::factory()->create([
        'created_by_user_id' => testSendAdmin()->id,
        'message' => 'Friday jollof, GHS 35 at Ashaiman. cedibites.com/r/A7X9Kp',
    ]);

    $this->actingAs(testSendAdmin(), 'sanctum')
        ->postJson("/v1/admin/campaigns/{$campaign->id}/test", ['phone' => '0241234567'])
        ->assertOk();

    // Not a paraphrase and not a preview. The same string the chunks would send.
    Http::assertSent(fn ($request) => $request['To'] === '233241234567'
        && $request['Content'] === 'Friday jollof, GHS 35 at Ashaiman. cedibites.com/r/A7X9Kp');
});

/*
 * The reason this is not simply "turn seed mode on and press send".
 *
 * Seed mode redirects every recipient to the staff list. Applied to a test it
 * would show somebody else's handset as proof that yours would have been fine,
 * which is worse than no test at all.
 */
it('goes to the number typed even with seed mode on', function () {
    hubtelAcceptsTest();

    $campaign = Campaign::factory()->create(['created_by_user_id' => testSendAdmin()->id]);

    $this->actingAs(testSendAdmin(), 'sanctum')
        ->postJson("/v1/admin/campaigns/{$campaign->id}/test", ['phone' => '0241234567'])
        ->assertOk();

    Http::assertSent(fn ($request) => $request['To'] === '233241234567');
    Http::assertNotSent(fn ($request) => $request['To'] === '233209999999');
});

/*
 * The one that matters most. A tested campaign is an unsent campaign as far as
 * every counter, every cost and the confirm screen are concerned.
 */
it('leaves the campaign a draft with every counter untouched', function () {
    hubtelAcceptsTest();

    $campaign = Campaign::factory()->create(['created_by_user_id' => testSendAdmin()->id]);

    $this->actingAs(testSendAdmin(), 'sanctum')
        ->postJson("/v1/admin/campaigns/{$campaign->id}/test", ['phone' => '0241234567'])
        ->assertOk();

    $fresh = $campaign->fresh();

    expect($fresh->status)->toBe(CampaignStatus::Draft)
        ->and($fresh->sent_count)->toBe(0)
        ->and($fresh->failed_count)->toBe(0)
        ->and($fresh->actual_cost)->toBeNull()
        ->and($fresh->batch_ids)->toBeNull()
        ->and($fresh->started_at)->toBeNull()
        ->and($fresh->approved_by_user_id)->toBeNull();
});

it('records who tested it, when, and to which number', function () {
    hubtelAcceptsTest();

    $campaign = Campaign::factory()->create(['created_by_user_id' => testSendAdmin()->id]);

    $this->actingAs(testSendAdmin(), 'sanctum')
        ->postJson("/v1/admin/campaigns/{$campaign->id}/test", ['phone' => '024 123 4567'])
        ->assertOk()
        // Read back on the campaign, so the screen can say it without a second call.
        ->assertJsonPath('data.last_tested_phone', '+233241234567');

    $fresh = $campaign->fresh();

    expect($fresh->last_tested_at)->not->toBeNull()
        ->and($fresh->last_tested_by_user_id)->toBe(testSendAdmin()->id);

    $this->assertDatabaseHas('activity_log', [
        'event' => 'campaign_tested',
        'subject_id' => $campaign->id,
        'causer_id' => testSendAdmin()->id,
    ]);
});

it('accepts a number in whatever shape it was pasted', function (string $typed) {
    hubtelAcceptsTest();

    $campaign = Campaign::factory()->create(['created_by_user_id' => testSendAdmin()->id]);

    $this->actingAs(testSendAdmin(), 'sanctum')
        ->postJson("/v1/admin/campaigns/{$campaign->id}/test", ['phone' => $typed])
        ->assertOk()
        ->assertJsonPath('data.last_tested_phone', '+233241234567');
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

    $campaign = Campaign::factory()->create(['created_by_user_id' => testSendAdmin()->id]);

    $this->actingAs(testSendAdmin(), 'sanctum')
        ->postJson("/v1/admin/campaigns/{$campaign->id}/test", ['phone' => '12345'])
        ->assertStatus(422);

    Http::assertNothingSent();
    expect($campaign->fresh()->last_tested_at)->toBeNull();
});

it('refuses to test a campaign that has already gone out', function () {
    Http::fake();

    $campaign = Campaign::factory()->sent()->create(['created_by_user_id' => testSendAdmin()->id]);

    $this->actingAs(testSendAdmin(), 'sanctum')
        ->postJson("/v1/admin/campaigns/{$campaign->id}/test", ['phone' => '0241234567'])
        ->assertStatus(422);

    Http::assertNothingSent();
});

it('refuses to test an empty message', function () {
    Http::fake();

    $campaign = Campaign::factory()->create([
        'created_by_user_id' => testSendAdmin()->id,
        'message' => '   ',
    ]);

    $this->actingAs(testSendAdmin(), 'sanctum')
        ->postJson("/v1/admin/campaigns/{$campaign->id}/test", ['phone' => '0241234567'])
        ->assertStatus(422);

    Http::assertNothingSent();
});

/*
 * A refused test must not leave the campaign claiming it was read on a phone.
 * That stamp is what somebody checks after a campaign goes out wrong.
 */
it('does not stamp the campaign when Hubtel refuses the text', function () {
    Http::fake(['*' => Http::response(['status' => 4109, 'statusDescription' => 'Payment required'], 200)]);

    $campaign = Campaign::factory()->create(['created_by_user_id' => testSendAdmin()->id]);

    $this->actingAs(testSendAdmin(), 'sanctum')
        ->postJson("/v1/admin/campaigns/{$campaign->id}/test", ['phone' => '0241234567'])
        ->assertStatus(422);

    expect($campaign->fresh()->last_tested_at)->toBeNull();
});

it('refuses a manager', function () {
    Http::fake();

    $manager = User::factory()->create(['phone' => '+233209999998']);
    $manager->assignRole('manager');

    $campaign = Campaign::factory()->create(['created_by_user_id' => testSendAdmin()->id]);

    $this->actingAs($manager, 'sanctum')
        ->postJson("/v1/admin/campaigns/{$campaign->id}/test", ['phone' => '0241234567'])
        ->assertForbidden();

    Http::assertNothingSent();
});
