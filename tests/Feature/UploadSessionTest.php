<?php

use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Domain\Inventory\Wastage\WastageService;
use App\Enums\Inventory\WastageReason;
use App\Enums\Permission;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\Uploads\Handlers\WastageEvidenceHandler;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * Phone-as-camera upload sessions.
 *
 * The problem: everyone works on a laptop and nobody carries a laptop to a crate
 * of spoiled chicken on the floor. The desktop shows a QR code, a phone scans
 * it, and a no-login page attaches photos and video to one claim.
 *
 * The same cast as WastageTest - Jesse manages a branch, Wilfred runs the mother
 * kitchen and signs for what he supplied - because the thing most worth testing
 * here is that the phone uploads AS the person who generated the code. Wastage
 * derives `stage` (declared vs inspection) from the actor, and an anonymous
 * upload would file the branch's own evidence as the warehouse's inspection.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Storage::fake('public');

    $this->wastages = app(WastageService::class);

    $this->warehouse = Location::factory()->warehouse()->create();
    $branch = Branch::factory()->create();
    $this->branch = Location::factory()->satellite()->create(['branch_id' => $branch->id]);

    // Wilfred sees everywhere. Jesse works at the branch and nowhere else.
    $this->wilfred = User::factory()->create();
    $this->wilfred->givePermissionTo(Permission::InventoryViewAllLocations->value);

    $this->jesse = User::factory()->create();
    // Evidence is gated on `view` rather than `record`: both ends of a
    // disagreement have to be able to put their account on the record.
    $this->jesse->givePermissionTo('view_inventory_catalog');
    Employee::factory()->create(['user_id' => $this->jesse->id])
        ->branches()->attach($branch->id);

    $this->chicken = Item::factory()->create(['name' => 'Chicken']);
    $this->rice = Item::factory()->create(['name' => 'Rice']);

    postStock($this->branch->id, $this->chicken->id, 30, 70.0);
    postStock($this->branch->id, $this->rice->id, 40, 2.0);
});

function postStock(int $locationId, int $itemId, float $qty, float $cost): void
{
    app(MovementPostingEngine::class)->post([
        'item_id' => $itemId,
        'location_id' => $locationId,
        'quantity' => $qty,
        'movement_type' => 'purchase',
        'unit_cost_at_time' => $cost,
        'idempotency_key' => "us-seed-{$locationId}-{$itemId}-".uniqid(),
    ]);
}

/** A live claim: 20 chickens at GHS 70 blows straight through the threshold. */
function openClaim(User $actor, Location $branch, Item $item): App\Models\Inventory\Wastage
{
    return app(WastageService::class)->record([
        'location_id' => $branch->id,
        'lines' => [['item_id' => $item->id, 'quantity' => 20, 'reason' => WastageReason::Spoiled->value]],
    ], $actor);
}

/** Mint a session over HTTP the way the laptop does, and dig the token out of the URL. */
function issueFor(App\Models\Inventory\Wastage $wastage): array
{
    $response = postJson('/v1/upload-sessions', [
        'target_type' => 'wastage',
        'target_id' => $wastage->id,
        'purpose' => WastageEvidenceHandler::PURPOSE,
    ]);

    $response->assertOk();
    $url = $response->json('data.url');

    return [
        'token' => basename(parse_url($url, PHP_URL_PATH)),
        'id' => $response->json('data.id'),
        'url' => $url,
    ];
}

// ── Minting ──────────────────────────────────────────────────────────────────

it('hands the laptop an absolute URL to draw as a QR code', function () {
    config(['app.frontend_url' => 'https://app.cedibites.com']);
    actingAs($this->jesse, 'sanctum');

    $wastage = openClaim($this->jesse, $this->branch, $this->chicken);
    $issued = issueFor($wastage);

    expect($issued['url'])->toStartWith('https://app.cedibites.com/u/')
        ->and($issued['token'])->toMatch('/^[A-Za-z0-9]{32}$/');
});

it('never stores the raw token, only its hash', function () {
    actingAs($this->jesse, 'sanctum');

    $wastage = openClaim($this->jesse, $this->branch, $this->chicken);
    $issued = issueFor($wastage);

    // The whole security posture rests on this: the token lives in the QR code
    // on screen and in the phone's URL bar, and nowhere else ever again. A
    // database leak has to yield nothing usable.
    $session = UploadSession::first();

    expect($session->token_hash)->not->toBe($issued['token'])
        ->and($session->token_hash)->toBe(hash('sha256', $issued['token']))
        ->and($session->toArray())->not->toHaveKey('token_hash');
});

it('refuses to draw a code for a claim that is already settled', function () {
    actingAs($this->jesse, 'sanctum');

    /*
     * A claim that has been DECIDED. This used to use a small self-approving
     * loss, which is what hid the real bug: under the threshold, and anywhere in
     * a warehouse, a claim approves itself the instant it is recorded, so
     * "Save and use phone" refused every ordinary write-off. Withdrawing the
     * claim settles it with an actual decision behind it.
     */
    $wastage = openClaim($this->jesse, $this->branch, $this->chicken);
    $this->wastages->cancel($wastage->fresh(), $this->jesse);

    postJson('/v1/upload-sessions', [
        'target_type' => 'wastage',
        'target_id' => $wastage->id,
        'purpose' => WastageEvidenceHandler::PURPOSE,
    ])->assertStatus(422);
});

it('refuses to draw a code for a claim at a location you do not work with', function () {
    $wastage = openClaim($this->jesse, $this->branch, $this->chicken);

    // Someone with no location grants at all - the QR route must not be a way
    // around the visibility rule the laptop endpoint already enforces.
    $stranger = User::factory()->create();
    actingAs($stranger, 'sanctum');

    postJson('/v1/upload-sessions', [
        'target_type' => 'wastage',
        'target_id' => $wastage->id,
        'purpose' => WastageEvidenceHandler::PURPOSE,
    ])->assertStatus(422);
});

it('will not accept an arbitrary model class over the wire', function () {
    actingAs($this->jesse, 'sanctum');

    postJson('/v1/upload-sessions', [
        'target_type' => \App\Models\User::class,
        'target_id' => $this->wilfred->id,
        'purpose' => WastageEvidenceHandler::PURPOSE,
    ])->assertStatus(422);
});

it('kills the previous code when the same person asks for another', function () {
    actingAs($this->jesse, 'sanctum');

    $wastage = openClaim($this->jesse, $this->branch, $this->chicken);
    $first = issueFor($wastage);
    $second = issueFor($wastage);

    // Pressing the button again is how you deal with a screen a room just saw.
    getJson("/v1/upload-sessions/{$first['token']}")->assertStatus(404);
    getJson("/v1/upload-sessions/{$second['token']}")->assertOk();
});

it('lets the branch and the warehouse each hold their own live code', function () {
    $wastage = openClaim($this->jesse, $this->branch, $this->chicken);

    actingAs($this->jesse, 'sanctum');
    $jesses = issueFor($wastage);

    actingAs($this->wilfred, 'sanctum');
    $wilfreds = issueFor($wastage);

    // They upload as different `stage`s, so revoking on issue is scoped to the
    // person, not the document - Wilfred asking for a code must not silently
    // break the one Jesse is walking to the cold room with.
    getJson("/v1/upload-sessions/{$jesses['token']}")->assertOk();
    getJson("/v1/upload-sessions/{$wilfreds['token']}")->assertOk();
});

// ── What the phone can see ───────────────────────────────────────────────────

it('shows the phone a reference and an instruction, and nothing about the claim', function () {
    $wastage = openClaim($this->jesse, $this->branch, $this->chicken);
    actingAs($this->jesse, 'sanctum');
    $issued = issueFor($wastage);

    $body = getJson("/v1/upload-sessions/{$issued['token']}")->assertOk()->json('data');

    expect($body['reference'])->toBe($wastage->reference)
        ->and($body['label'])->toBeString()
        ->and($body['remaining'])->toBe($body['max_files']);

    // The token is a bearer credential inside a screenshot-able square. Whoever
    // holds it sees this response, so it must not carry the argument itself.
    $raw = json_encode($body);
    expect($raw)->not->toContain('Chicken')
        ->and($raw)->not->toContain((string) $this->branch->name)
        ->and($raw)->not->toContain('1400'); // 20 x GHS 70
});

it('gives an unknown token the same answer as an expired one', function () {
    $unknown = getJson('/v1/upload-sessions/'.str_repeat('a', 32));

    // Never an oracle for which tokens exist.
    $unknown->assertStatus(404);
    expect($unknown->json('message'))->toContain('not valid');
});

// ── Uploading ────────────────────────────────────────────────────────────────

it('uploads as the person who generated the code, so `stage` stays honest', function () {
    $wastage = openClaim($this->jesse, $this->branch, $this->chicken);

    // Jesse raised the claim: his phone's photos are his case.
    actingAs($this->jesse, 'sanctum');
    $jesses = issueFor($wastage);

    // Wilfred is inspecting what arrived: his are the counter-evidence.
    actingAs($this->wilfred, 'sanctum');
    $wilfreds = issueFor($wastage);

    // Both phones are logged out. The token is all they have.
    app('auth')->forgetGuards();

    postJson("/v1/upload-sessions/{$jesses['token']}/files", [
        'file' => UploadedFile::fake()->image('crate.jpg'),
    ])->assertOk();

    postJson("/v1/upload-sessions/{$wilfreds['token']}/files", [
        'file' => UploadedFile::fake()->image('on-arrival.jpg'),
    ])->assertOk();

    $photos = $wastage->fresh('photos')->photos;

    expect($photos)->toHaveCount(2)
        ->and($photos->firstWhere('uploaded_by', $this->jesse->id)->stage)->toBe('declared')
        ->and($photos->firstWhere('uploaded_by', $this->wilfred->id)->stage)->toBe('inspection');

    Storage::disk('public')->assertExists($photos->first()->path);
});

it('takes video as well as stills', function () {
    $wastage = openClaim($this->jesse, $this->branch, $this->chicken);
    actingAs($this->jesse, 'sanctum');
    $issued = issueFor($wastage);
    app('auth')->forgetGuards();

    // An iPhone records .mov; a still cannot carry the smell argument that a
    // ten-second pan across a crate can.
    postJson("/v1/upload-sessions/{$issued['token']}/files", [
        'file' => UploadedFile::fake()->create('crate.mov', 4000, 'video/quicktime'),
    ])->assertOk();

    $photo = $wastage->fresh('photos')->photos->first();
    expect($photo->mime_type)->toBe('video/quicktime');
});

it('refuses a clip too big to survive the trip', function () {
    $wastage = openClaim($this->jesse, $this->branch, $this->chicken);
    actingAs($this->jesse, 'sanctum');
    $issued = issueFor($wastage);
    app('auth')->forgetGuards();

    // 1080p runs 15-30 MB per 15 seconds; the cap is 50 MB.
    $response = postJson("/v1/upload-sessions/{$issued['token']}/files", [
        'file' => UploadedFile::fake()->create('long.mp4', 60_000, 'video/mp4'),
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('15 seconds');
    expect($wastage->fresh('photos')->photos)->toHaveCount(0);
});

it('refuses anything that is not a photo or a video', function () {
    $wastage = openClaim($this->jesse, $this->branch, $this->chicken);
    actingAs($this->jesse, 'sanctum');
    $issued = issueFor($wastage);
    app('auth')->forgetGuards();

    postJson("/v1/upload-sessions/{$issued['token']}/files", [
        'file' => UploadedFile::fake()->create('payload.pdf', 10, 'application/pdf'),
    ])->assertStatus(422);
});

it('stops at the file budget so a leaked code cannot fill the disk', function () {
    $wastage = openClaim($this->jesse, $this->branch, $this->chicken);
    actingAs($this->jesse, 'sanctum');

    // Mint by hand rather than over HTTP so the cap is reachable in a test.
    $issued = app(App\Services\Uploads\UploadSessionService::class)->issue(
        $wastage, $this->jesse, WastageEvidenceHandler::PURPOSE, maxFiles: 2,
    );
    app('auth')->forgetGuards();

    foreach (range(1, 2) as $n) {
        postJson("/v1/upload-sessions/{$issued['token']}/files", [
            'file' => UploadedFile::fake()->image("crate-{$n}.jpg"),
        ])->assertOk();
    }

    postJson("/v1/upload-sessions/{$issued['token']}/files", [
        'file' => UploadedFile::fake()->image('crate-3.jpg'),
    ])->assertStatus(404);

    expect($wastage->fresh('photos')->photos)->toHaveCount(2);
});

// ── Dying ────────────────────────────────────────────────────────────────────

it('expires on its own', function () {
    $wastage = openClaim($this->jesse, $this->branch, $this->chicken);
    actingAs($this->jesse, 'sanctum');
    $issued = issueFor($wastage);
    app('auth')->forgetGuards();

    $this->travel(11)->minutes();

    getJson("/v1/upload-sessions/{$issued['token']}")->assertStatus(404);
    postJson("/v1/upload-sessions/{$issued['token']}/files", [
        'file' => UploadedFile::fake()->image('late.jpg'),
    ])->assertStatus(404);
});

it('can be killed by hand the moment the wrong person sees the screen', function () {
    $wastage = openClaim($this->jesse, $this->branch, $this->chicken);
    actingAs($this->jesse, 'sanctum');
    $issued = issueFor($wastage);

    deleteJson("/v1/upload-sessions/{$issued['id']}")->assertOk();
    app('auth')->forgetGuards();

    getJson("/v1/upload-sessions/{$issued['token']}")->assertStatus(404);
});

it('will not let a colleague kill a code that is not theirs', function () {
    $wastage = openClaim($this->jesse, $this->branch, $this->chicken);
    actingAs($this->jesse, 'sanctum');
    $issued = issueFor($wastage);

    // The session acts AS Jesse, so it is Jesse's credential to manage. Wilfred
    // wanting it gone settles the claim instead, which closes every code on it.
    actingAs($this->wilfred, 'sanctum');
    deleteJson("/v1/upload-sessions/{$issued['id']}")->assertStatus(404);
});

it('dies the moment the claim it belongs to is settled', function () {
    $wastage = openClaim($this->jesse, $this->branch, $this->chicken);
    actingAs($this->jesse, 'sanctum');
    $issued = issueFor($wastage);

    // Jesse thinks better of it and withdraws his own claim. However a claim
    // settles - withdrawn, approved, refused - its photo set becomes the record
    // of what was decided on, and a live QR code must not outlive that.
    $this->wastages->cancel($wastage->fresh(), $this->jesse);

    app('auth')->forgetGuards();
    getJson("/v1/upload-sessions/{$issued['token']}")->assertStatus(404);

    expect(UploadSession::find($issued['id'])->consumed_at)->not->toBeNull();
});

it('records who used it, for a credential anyone near the screen could hold', function () {
    $wastage = openClaim($this->jesse, $this->branch, $this->chicken);
    actingAs($this->jesse, 'sanctum');
    $issued = issueFor($wastage);
    app('auth')->forgetGuards();

    postJson(
        "/v1/upload-sessions/{$issued['token']}/files",
        ['file' => UploadedFile::fake()->image('crate.jpg')],
        ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)'],
    )->assertOk();

    $session = UploadSession::find($issued['id']);

    expect($session->files_uploaded)->toBe(1)
        ->and($session->last_used_at)->not->toBeNull()
        ->and($session->last_ip)->not->toBeNull()
        ->and($session->last_user_agent)->toContain('iPhone');
});

// ── The desktop path, now that it takes video too ────────────────────────────

it('accepts video on the laptop endpoint as well, so the two paths agree', function () {
    $wastage = openClaim($this->jesse, $this->branch, $this->chicken);
    actingAs($this->jesse, 'sanctum');

    postJson("/v1/inventory/wastages/{$wastage->id}/photos", [
        'photo' => UploadedFile::fake()->create('crate.webm', 3000, 'video/webm'),
    ])->assertOk()
        ->assertJsonPath('data.photos.0.kind', 'video');
});
