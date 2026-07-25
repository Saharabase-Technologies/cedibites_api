<?php

use App\Models\User;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission as SpatiePermission;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * Per-page notes: one report roams several pages, and each page can carry its
 * own words — text, a voice clip, or both. The report's own description and
 * voice note remain the overall summary.
 *
 * I2 still governs: a malformed note degrades (dropped, or kept without its
 * audio), it never rejects the report.
 */
beforeEach(function () {
    Storage::fake('public');
    $this->reporter = User::factory()->create();
    // Reading a report back is a triage action.
    $this->reporter->givePermissionTo(
        SpatiePermission::findOrCreate('feedback.triage', 'api')
    );
    actingAs($this->reporter);
});

/** Multipart submit — the shape the widget actually sends. */
function submitWithNotes(array $notes, array $audio = [])
{
    return postJson('/v1/feedback/reports', [
        'payload' => json_encode(['description' => 'roamed a few pages', 'notes' => $notes]),
        'note_audio' => $audio,
    ]);
}

it('records several voice notes on one report, each pinned to its page', function () {
    $id = submitWithNotes(
        [
            ['route' => '/inventory/requisitions/new', 'body' => 'branch picker is wrong', 'audio_index' => 0],
            ['route' => '/inventory/transfers', 'audio_index' => 1],
        ],
        [File::create('one.webm', 20), File::create('two.webm', 20)],
    )->assertSuccessful()->json('data.id');

    $notes = getJson("/v1/feedback/reports/{$id}")->assertSuccessful()->json('data.notes');

    expect($notes)->toHaveCount(2)
        ->and($notes[0]['route'])->toBe('/inventory/requisitions/new')
        ->and($notes[0]['body'])->toBe('branch picker is wrong')
        ->and($notes[0]['audio_url'])->not->toBeNull()
        ->and($notes[1]['route'])->toBe('/inventory/transfers')
        ->and($notes[1]['body'])->toBeNull()
        ->and($notes[1]['audio_url'])->not->toBeNull()
        // Two clips, two distinct uploads.
        ->and($notes[0]['audio_url'])->not->toBe($notes[1]['audio_url']);
});

it('keeps notes in the order the reporter recorded them', function () {
    $id = submitWithNotes([
        ['route' => '/a', 'body' => 'first'],
        ['route' => '/b', 'body' => 'second'],
        ['route' => '/c', 'body' => 'third'],
    ])->assertSuccessful()->json('data.id');

    $notes = getJson("/v1/feedback/reports/{$id}")->json('data.notes');

    expect(array_column($notes, 'body'))->toBe(['first', 'second', 'third'])
        ->and(array_column($notes, 'position'))->toBe([0, 1, 2]);
});

it('accepts a text-only note with no voice clip', function () {
    $id = submitWithNotes([['route' => '/inventory/items', 'body' => 'just typing']])
        ->assertSuccessful()->json('data.id');

    $notes = getJson("/v1/feedback/reports/{$id}")->json('data.notes');

    expect($notes)->toHaveCount(1)
        ->and($notes[0]['audio_url'])->toBeNull();
});

it('drops an empty note rather than storing a blank row (I2)', function () {
    $id = submitWithNotes([
        ['route' => '/a', 'body' => '   '],
        ['route' => '/b', 'body' => 'kept'],
    ])->assertSuccessful()->json('data.id');

    $notes = getJson("/v1/feedback/reports/{$id}")->json('data.notes');

    expect($notes)->toHaveCount(1)
        ->and($notes[0]['body'])->toBe('kept');
});

it('degrades a note whose audio_index points at nothing, keeping its text (I2)', function () {
    $id = submitWithNotes(
        [['route' => '/a', 'body' => 'text survives', 'audio_index' => 7]],
        [File::create('one.webm', 20)],
    )->assertSuccessful()->json('data.id');

    $notes = getJson("/v1/feedback/reports/{$id}")->json('data.notes');

    expect($notes)->toHaveCount(1)
        ->and($notes[0]['body'])->toBe('text survives')
        ->and($notes[0]['audio_url'])->toBeNull();
});

it('leaves a report with no notes reading exactly as before', function () {
    $id = postJson('/v1/feedback/reports', ['description' => 'plain old report'])
        ->assertSuccessful()->json('data.id');

    $data = getJson("/v1/feedback/reports/{$id}")->assertSuccessful()->json('data');

    expect($data['notes'])->toBe([])
        ->and($data['description'])->toBe('plain old report');
});
