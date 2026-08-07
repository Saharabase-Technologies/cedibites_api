<?php

use App\Models\LinkClick;
use App\Models\ShortLink;
use App\Models\User;
use App\Services\ShortLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'short_links.base_url' => 'https://cedibites.com',
        'short_links.token_length' => 6,
        'short_links.own_hosts' => ['cedibites.com', 'app.cedibites.com'],
    ]);

    SpatieRole::findOrCreate('admin', 'api')
        ->givePermissionTo(SpatiePermission::findOrCreate('manage_campaigns', 'api'));

    SpatieRole::findOrCreate('manager', 'api');
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

function linkAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('admin');

    return $user;
}

function linkManager(): User
{
    $user = User::factory()->create();
    $user->assignRole('manager');

    return $user;
}

// ─── Public resolve ──────────────────────────────────────────────────────────

describe('POST /links/{token}/resolve', function () {
    it('answers with the target and counts the tap', function () {
        $link = ShortLink::factory()->create([
            'token' => 'A7X9Kp',
            'target_url' => 'https://app.cedibites.com/promo/friday',
        ]);

        $this->postJson('/v1/links/A7X9Kp/resolve')
            ->assertSuccessful()
            ->assertJsonPath('data.target_url', 'https://app.cedibites.com/promo/friday');

        expect($link->fresh()->click_count)->toBe(1)
            ->and(LinkClick::where('short_link_id', $link->id)->count())->toBe(1);
    });

    it('needs no authentication — the customer has no account', function () {
        ShortLink::factory()->create(['token' => 'Open01']);

        $this->postJson('/v1/links/Open01/resolve')->assertSuccessful();
    });

    /*
     * The user agent belongs to the customer, not to the server that forwarded
     * the request. Our Next.js handler is the only caller, so reading it off the
     * incoming request would record the same value 28,000 times and the click
     * log would say nothing about who tapped.
     */
    it('stores the forwarded user agent rather than the caller\'s', function () {
        $link = ShortLink::factory()->create(['token' => 'Fwd001']);

        $this->postJson('/v1/links/Fwd001/resolve', [
            'user_agent' => 'Mozilla/5.0 (Linux; Android 13; Infinix X6819)',
            'referer' => 'android-app://com.google.android.gm',
        ])->assertSuccessful();

        $click = LinkClick::where('short_link_id', $link->id)->sole();

        expect($click->user_agent)->toBe('Mozilla/5.0 (Linux; Android 13; Infinix X6819)')
            ->and($click->referer)->toBe('android-app://com.google.android.gm');
    });

    it('404s on a token that does not exist', function () {
        $this->postJson('/v1/links/Nobody/resolve')->assertNotFound();
    });

    /*
     * Expired and never-existed answer identically. Distinguishing them turns
     * this endpoint into a way of testing whether a token is real.
     */
    it('404s on an expired link, and does not count the tap', function () {
        $link = ShortLink::factory()->expired()->create(['token' => 'Gone01']);

        $this->postJson('/v1/links/Gone01/resolve')->assertNotFound();

        expect($link->fresh()->click_count)->toBe(0)
            ->and(LinkClick::count())->toBe(0);
    });

    it('still redirects when the click cannot be recorded', function () {
        $link = ShortLink::factory()->create(['token' => 'Frail1']);

        // A recording failure must never be the reason a customer does not reach
        // the menu. Losing one datapoint out of 28,000 is survivable; a 500
        // where a redirect should have been is not.
        Schema::drop('link_clicks');

        $this->postJson('/v1/links/Frail1/resolve')
            ->assertSuccessful()
            ->assertJsonPath('data.target_url', $link->target_url);
    });
});

// ─── URLs ────────────────────────────────────────────────────────────────────

describe('the two forms of the URL', function () {
    it('builds the absolute URL from the configured base', function () {
        $link = ShortLink::factory()->create(['token' => 'A7X9Kp']);

        expect($link->url())->toBe('https://cedibites.com/r/A7X9Kp');
    });

    /*
     * Never write the scheme into a message. Handsets auto-link a bare domain,
     * so `https://` costs eight characters and buys nothing — and eight
     * characters is the whole margin on a message sitting at 161.
     */
    it('strips the scheme for the SMS form', function () {
        $link = ShortLink::factory()->create(['token' => 'A7X9Kp']);

        expect($link->smsUrl())->toBe('cedibites.com/r/A7X9Kp')
            ->and(strlen($link->smsUrl()))->toBe(22);
    });

    /*
     * Only the token is stored, so moving to a shorter domain repoints every
     * future link while everything already sent keeps resolving.
     */
    it('follows the base when the domain changes', function () {
        $link = ShortLink::factory()->create(['token' => 'A7X9Kp']);

        config(['short_links.base_url' => 'https://cdb.gh']);

        expect($link->url())->toBe('https://cdb.gh/r/A7X9Kp');
    });
});

// ─── Admin ───────────────────────────────────────────────────────────────────

describe('admin link management', function () {
    it('rejects unauthenticated requests', function () {
        $this->getJson('/v1/admin/links')->assertUnauthorized();
    });

    /*
     * Same ceiling as the contact export. A manager runs a branch; a link wears
     * the whole company's brand.
     */
    it('refuses a manager', function () {
        $this->actingAs(linkManager(), 'sanctum')
            ->getJson('/v1/admin/links')
            ->assertForbidden();
    });

    it('creates a link with a token of the configured length', function () {
        $response = $this->actingAs(linkAdmin(), 'sanctum')
            ->postJson('/v1/admin/links', [
                'label' => 'August Friday jollof',
                'target_url' => 'https://app.cedibites.com/promo/friday',
            ])
            ->assertCreated();

        expect(strlen($response->json('data.token')))->toBe(6)
            ->and($response->json('data.sms_url'))->toStartWith('cedibites.com/r/')
            ->and($response->json('data.is_external'))->toBeFalse();
    });

    it('flags a target that is not ours', function () {
        $response = $this->actingAs(linkAdmin(), 'sanctum')
            ->postJson('/v1/admin/links', [
                'label' => 'Partner survey',
                'target_url' => 'https://forms.gle/abc123',
            ])
            ->assertCreated();

        expect($response->json('data.is_external'))->toBeTrue();
    });

    /*
     * Without the scheme restriction our branded domain becomes a way of running
     * somebody else's script behind our name.
     */
    it('refuses a javascript: target', function () {
        $this->actingAs(linkAdmin(), 'sanctum')
            ->postJson('/v1/admin/links', [
                'label' => 'Nope',
                'target_url' => 'javascript:alert(1)',
            ])
            ->assertJsonValidationErrors('target_url');
    });

    it('refuses a target pointing back at the shortener', function () {
        $this->actingAs(linkAdmin(), 'sanctum')
            ->postJson('/v1/admin/links', [
                'label' => 'Loop',
                'target_url' => 'https://cedibites.com/r/A7X9Kp',
            ])
            ->assertJsonValidationErrors('target_url');
    });

    /*
     * Repointing is half the argument for answering with a 302: a link already
     * printed on 28,000 handsets is the one you most need to be able to fix.
     */
    it('repoints an existing link without changing its token', function () {
        $link = ShortLink::factory()->create([
            'token' => 'Fix001',
            'target_url' => 'https://app.cedibites.com/wrong',
        ]);

        $this->actingAs(linkAdmin(), 'sanctum')
            ->patchJson("/v1/admin/links/{$link->id}", [
                'target_url' => 'https://app.cedibites.com/right',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.token', 'Fix001');

        $this->postJson('/v1/links/Fix001/resolve')
            ->assertJsonPath('data.target_url', 'https://app.cedibites.com/right');
    });

    it('renames a link without restating where it goes', function () {
        $link = ShortLink::factory()->create(['target_url' => 'https://app.cedibites.com/menu']);

        $this->actingAs(linkAdmin(), 'sanctum')
            ->patchJson("/v1/admin/links/{$link->id}", ['label' => 'Renamed'])
            ->assertSuccessful()
            ->assertJsonPath('data.label', 'Renamed')
            ->assertJsonPath('data.target_url', 'https://app.cedibites.com/menu');
    });

    it('lists links newest first with their click counts', function () {
        ShortLink::factory()->create(['label' => 'Older', 'click_count' => 7, 'created_at' => now()->subDay()]);
        ShortLink::factory()->create(['label' => 'Newer', 'created_at' => now()]);

        $data = $this->actingAs(linkAdmin(), 'sanctum')
            ->getJson('/v1/admin/links')
            ->assertSuccessful()
            ->json('data.data');

        expect($data[0]['label'])->toBe('Newer')
            ->and($data[1]['click_count'])->toBe(7);
    });
});

// ─── Retention ───────────────────────────────────────────────────────────────

/*
 * The trap this guards: if campaign reporting read its click-through from
 * link_clicks, the figure would silently change at the retention boundary — a
 * number shown to the board last month returning something different this
 * month. The per-link total is what reporting reads, and pruning must not touch
 * it.
 */
it('prunes the click timeline but keeps the total', function () {
    $link = ShortLink::factory()->create(['click_count' => 3]);

    LinkClick::create(['short_link_id' => $link->id, 'clicked_at' => now()->subDays(400)]);
    LinkClick::create(['short_link_id' => $link->id, 'clicked_at' => now()->subDays(300)]);
    LinkClick::create(['short_link_id' => $link->id, 'clicked_at' => now()->subDay()]);

    $pruned = app(ShortLinkService::class)->pruneClicks(180);

    expect($pruned)->toBe(2)
        ->and(LinkClick::count())->toBe(1)
        ->and($link->fresh()->click_count)->toBe(3);
});
