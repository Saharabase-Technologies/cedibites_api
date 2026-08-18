<?php

use App\Enums\Role as RoleEnum;
use App\Services\Platform\RuntimeSettings;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
|--------------------------------------------------------------------------
| Toggles instead of SSH
|--------------------------------------------------------------------------
|
| DB overrides on top of `.env`, on an allowlist. The two properties that must
| hold: nothing outside the allowlist can be written, and an override actually
| beats the config value — otherwise the panel is a placebo.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->runtime = app(RuntimeSettings::class);
});

it('follows the server file until somebody overrides it', function () {
    config()->set('staff_messaging.automation_enabled', false);

    expect($this->runtime->get('staff_messaging.automation_enabled'))->toBeFalse();

    $this->runtime->set('staff_messaging.automation_enabled', true);

    expect($this->runtime->get('staff_messaging.automation_enabled'))->toBeTrue();
});

it('goes back to the server file when the override is dropped', function () {
    config()->set('staff_messaging.automation_enabled', false);
    $this->runtime->set('staff_messaging.automation_enabled', true);

    $this->runtime->revert('staff_messaging.automation_enabled');

    expect($this->runtime->get('staff_messaging.automation_enabled'))->toBeFalse();
});

it('says where each value is coming from', function () {
    config()->set('staff_messaging.lookback_hours', 24);

    $before = collect($this->runtime->all())->firstWhere('key', 'staff_messaging.lookback_hours');
    expect($before['source'])->toBe('env')->and($before['value'])->toBe(24);

    $this->runtime->set('staff_messaging.lookback_hours', 48);

    $after = collect($this->runtime->all())->firstWhere('key', 'staff_messaging.lookback_hours');
    expect($after['source'])->toBe('override')
        ->and($after['value'])->toBe(48)
        // The default is still reported, so "what was it before I touched it?"
        // is answerable from the screen.
        ->and($after['default'])->toBe(24);
});

it('refuses a key that is not on the allowlist', function () {
    expect(fn () => $this->runtime->set('app.key', 'haha'))
        ->toThrow(InvalidArgumentException::class);
});

it('exposes no credentials at all', function () {
    $keys = collect($this->runtime->definitions())->pluck('key')->implode(' ');

    // A credential reaching this list would put it behind an HTTP surface. The
    // allowlist is what prevents it; this is the alarm if one ever slips in.
    expect($keys)->not->toContain('key')
        ->and($keys)->not->toContain('secret')
        ->and($keys)->not->toContain('password')
        ->and($keys)->not->toContain('token');
});

it('clamps a number rather than accepting something destructive', function () {
    // 100000 hours of lookback is a full table scan every five minutes.
    $value = $this->runtime->set('staff_messaging.lookback_hours', 100000);

    expect($value)->toBe(168);
});

it('lets a tech admin read and change settings over HTTP', function () {
    $techAdmin = msgStaff(RoleEnum::TechAdmin->value);

    $this->actingAs($techAdmin)
        ->getJson('/v1/platform/settings')
        ->assertSuccessful()
        ->assertJsonPath('data.environment', config('app.env'));

    $this->actingAs($techAdmin)
        ->putJson('/v1/platform/settings', [
            'key' => 'staff_messaging.recipient_hourly_cap',
            'value' => 5,
        ])
        ->assertSuccessful();

    expect($this->runtime->get('staff_messaging.recipient_hourly_cap'))->toBe(5);
});

it('refuses an admin who is not tech admin', function () {
    // The business owner deliberately does not hold the platform tools.
    $admin = msgStaff(RoleEnum::Admin->value);

    $this->actingAs($admin)->getJson('/v1/platform/settings')->assertForbidden();
});

it('refuses an unknown key over HTTP without touching anything', function () {
    $techAdmin = msgStaff(RoleEnum::TechAdmin->value);

    $this->actingAs($techAdmin)
        ->putJson('/v1/platform/settings', ['key' => 'app.debug', 'value' => true])
        ->assertStatus(422);
});

it('actually stops the rule engine when the switch is pulled from the panel', function () {
    // The property that makes the panel worth having: the guard reads the
    // override, not config(), so pulling the switch takes effect without a
    // deploy or a worker restart.
    config()->set('staff_messaging.automation_enabled', true);
    $this->runtime->set('staff_messaging.automation_enabled', false);

    $guard = app(\App\Services\StaffMessaging\StaffRuleGuard::class);
    $rule = \App\Models\StaffMessageRule::factory()->live()->create();

    expect($guard->suppressionFor($rule, 1, 'App\Models\Order:1'))
        ->toBe(\App\Enums\StaffMessageSuppression::FeatureOff);
});

it('actually governs campaign test mode from the panel', function () {
    // The lesson already learned with the messaging kill switch: a toggle the
    // send path does not read is a placebo, and this is the one where the
    // placebo texts real customers.
    config()->set('campaigns.seed_mode', true);

    $sender = app(\App\Services\Campaigns\CampaignSender::class);
    expect($sender->seedMode())->toBeTrue();

    $this->runtime->set('campaigns.seed_mode', false);

    expect($sender->seedMode())->toBeFalse();
});

it('keeps campaign test mode on when nothing has been set', function () {
    // Every fallback in the chain errs towards nobody being texted.
    expect($this->runtime->get('campaigns.seed_mode'))->toBeTrue();
});
