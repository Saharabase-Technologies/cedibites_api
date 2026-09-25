<?php

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchOperatingHour;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * A branch opened or closed by hand stays that way for the day, not for ever.
 *
 * The override lives on the weekday's row and used to be read with no date, so
 * one Sunday's close repeated every Sunday. Prod carried four of these in
 * September 2026: Ashaiman closed online every Sunday, Lakeside and East Legon
 * every Monday, East Legon open around the clock every Sunday. The tills never
 * noticed, because extended access let them sell anyway.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    // Sunday 27 September 2026, mid-afternoon. Hours are 08:00 to 22:00.
    $this->travelTo(Carbon::parse('2026-09-27 14:00:00'));
    $this->branch = Branch::factory()->create(['name' => 'Ashaiman']);
});

function sundayRow(Branch $branch): BranchOperatingHour
{
    return $branch->operatingHours()->where('day_of_week', 'sunday')->sole();
}

function setOverride(Branch $branch, bool $open, string $at): void
{
    sundayRow($branch)->update(['manual_override_open' => $open, 'manual_override_at' => Carbon::parse($at)]);
}

it('holds a close by hand for the rest of the day', function () {
    setOverride($this->branch, false, '2026-09-27 12:30:00');

    expect($this->branch->fresh()->isCurrentlyOpen())->toBeFalse();
});

it('does not close the branch again the same weekday next week', function () {
    setOverride($this->branch, false, '2026-09-20 12:30:00');

    expect($this->branch->fresh()->isCurrentlyOpen())->toBeTrue();
});

it('does not keep a branch open around the clock the next week', function () {
    setOverride($this->branch, true, '2026-09-20 23:00:00');
    $this->travelTo(Carbon::parse('2026-09-27 05:00:00'));

    expect($this->branch->fresh()->isCurrentlyOpen())->toBeFalse();
});

it('lets a close pressed after midnight lapse before the next morning', function () {
    // East Legon trades past midnight. Shut by hand at 01:00 on Sunday, that
    // closes Saturday's business day; Sunday opens as normal at 08:00.
    setOverride($this->branch, false, '2026-09-27 01:00:00');

    $this->travelTo(Carbon::parse('2026-09-27 02:30:00'));
    expect($this->branch->fresh()->isCurrentlyOpen())->toBeFalse();

    $this->travelTo(Carbon::parse('2026-09-27 10:00:00'));
    expect($this->branch->fresh()->isCurrentlyOpen())->toBeTrue();
});

it('does not tell customers about an override that has lapsed', function () {
    setOverride($this->branch, false, '2026-09-20 12:30:00');

    $hours = $this->getJson("/v1/branches/{$this->branch->id}")
        ->assertSuccessful()
        ->json('data.operating_hours.sunday');

    expect($hours['manual_override_open'])->toBeNull();
});

it('still tells customers about today\'s', function () {
    setOverride($this->branch, false, '2026-09-27 12:30:00');

    $data = $this->getJson("/v1/branches/{$this->branch->id}")->assertSuccessful()->json('data');

    expect($data['operating_hours']['sunday']['manual_override_open'])->toBeFalse()
        ->and($data['is_open'])->toBeFalse();
});

it('closes from the real state, not from last week\'s leftover', function () {
    // A stale "closed" row used to make the toggle think the branch was shut,
    // so pressing Close opened it.
    setOverride($this->branch, false, '2026-09-20 12:30:00');
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->patchJson("/v1/admin/branches/{$this->branch->id}/toggle-status")
        ->assertSuccessful()
        ->assertJsonPath('data.is_open', false);

    expect($this->branch->fresh()->isCurrentlyOpen())->toBeFalse();
});

it('records who opened or closed a branch by hand', function () {
    $admin = User::factory()->create(['name' => 'Platform Admin']);
    $admin->assignRole('admin');

    $this->actingAs($admin)->patchJson("/v1/admin/branches/{$this->branch->id}/toggle-status")->assertSuccessful();
    $this->actingAs($admin)->deleteJson("/v1/admin/branches/{$this->branch->id}/manual-override")->assertSuccessful();

    $entries = ActivityLog::where('log_name', 'admin')
        ->whereIn('event', ['branch_closed_by_hand', 'branch_override_cleared'])
        ->orderBy('id')
        ->get();

    expect($entries->pluck('event')->all())->toBe(['branch_closed_by_hand', 'branch_override_cleared'])
        ->and($entries->every(fn ($e) => $e->causer_id === $admin->id && $e->subject_id === $this->branch->id))->toBeTrue()
        ->and($entries->first()->description)->toBe('Ashaiman closed by hand for today');
});
