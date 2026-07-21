<?php

use App\Models\Branch;
use App\Models\FeedbackReport;
use App\Models\RequestLog;
use App\Models\User;

use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * Phase 1 guards the two safety invariants that separate a feedback tool that
 * helps from one that becomes the bug: I2 (fields degrade, never reject) and
 * P4 (correlate by request id, not time).
 */
beforeEach(function () {
    $this->reporter = User::factory()->create();
});

it('accepts a report and assigns a sequential number', function () {
    actingAs($this->reporter);

    $first = postJson('/v1/feedback/reports', [
        'description' => 'first', 'severity' => 'blocking',
    ])->assertSuccessful();

    $second = postJson('/v1/feedback/reports', [
        'description' => 'second', 'severity' => 'annoying',
    ])->assertSuccessful();

    expect($first->json('data.number'))->toBe(1)
        ->and($second->json('data.number'))->toBe(2);
});

it('trims oversized diagnostic arrays instead of rejecting them (I2)', function () {
    actingAs($this->reporter);

    // 300 console entries — over the 200 cap. Must be TRIMMED to newest, not 400'd.
    $entries = collect(range(1, 300))
        ->map(fn ($i) => ['level' => 'log', 'message' => "line {$i}", 'at' => $i])
        ->all();

    $res = postJson('/v1/feedback/reports', [
        'description' => 'noisy', 'console_entries' => $entries,
    ])->assertSuccessful();

    $report = FeedbackReport::find($res->json('data.id'));
    expect($report->console_entries)->toHaveCount(200)
        // kept the NEWEST 200 — first surviving entry is #101.
        ->and($report->console_entries[0]['message'])->toBe('line 101');
});

it('coerces a bogus branch id to null instead of 400ing the report (C1/I2)', function () {
    actingAs($this->reporter);

    postJson('/v1/feedback/reports', [
        'description' => 'bad ctx', 'branch_id' => 999999, // does not exist
    ])->assertSuccessful();

    expect(FeedbackReport::first()->branch_id)->toBeNull();
});

it('keeps a real branch id when it exists', function () {
    actingAs($this->reporter);
    $branch = Branch::factory()->create();

    postJson('/v1/feedback/reports', [
        'description' => 'good ctx', 'branch_id' => $branch->id,
    ])->assertSuccessful();

    expect(FeedbackReport::first()->branch_id)->toBe($branch->id);
});

it('coerces an unknown severity to the default (I2)', function () {
    actingAs($this->reporter);

    postJson('/v1/feedback/reports', [
        'description' => 'weird', 'severity' => 'catastrophic',
    ])->assertSuccessful();

    expect(FeedbackReport::first()->severity)->toBe('annoying');
});

it('correlates backend logs by request id, not by time (P4)', function () {
    // The logs endpoint is triage-gated.
    $this->seed(\Database\Seeders\PermissionSeeder::class);
    $this->reporter->givePermissionTo('feedback.triage');
    actingAs($this->reporter);

    // Two log rows from different requests; the report only references one.
    RequestLog::create([
        'request_id' => 'mine-123', 'method' => 'GET', 'path' => 'v1/x',
        'status_code' => 200, 'level' => 'info', 'created_at' => now(),
    ]);
    RequestLog::create([
        'request_id' => 'someone-else', 'method' => 'GET', 'path' => 'v1/y',
        'status_code' => 200, 'level' => 'info', 'created_at' => now(),
    ]);

    $res = postJson('/v1/feedback/reports', [
        'description' => 'r', 'request_ids' => ['mine-123'],
    ])->assertSuccessful();

    $logs = getJson("/v1/feedback/reports/{$res->json('data.id')}/logs")
        ->assertSuccessful()
        ->json('data');

    expect($logs)->toHaveCount(1)
        ->and($logs[0]['request_id'])->toBe('mine-123');
});

it('rejects an oversized file — the one hard limit that is allowed', function () {
    actingAs($this->reporter);

    $huge = \Illuminate\Http\Testing\File::create('big.png', 6 * 1024); // 6 MB > 5 MB cap

    postJson('/v1/feedback/reports', [
        'description' => 'big shot', 'screenshots' => [$huge],
    ])->assertStatus(422);
});

it('is fail-open — a request-log write failure never breaks the request (I3)', function () {
    actingAs($this->reporter);

    // Break the log table so the middleware's write throws on the way out.
    Schema::drop('request_logs');

    // The real request must still succeed — logging is down, the app is not.
    getJson('/v1/feedback/my-reports')->assertSuccessful();
});
