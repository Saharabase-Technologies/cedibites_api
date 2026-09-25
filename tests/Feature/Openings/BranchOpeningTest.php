<?php

use App\Enums\EmployeeStatus;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchOpening;
use App\Models\BranchOpeningAnswer;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\OpeningChecklistItem;
use App\Models\User;
use App\Notifications\AdminAlertNotification;
use App\Services\Openings\BranchOpeningService;
use App\Services\Platform\RuntimeSettings;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Opening the branch for the day
|--------------------------------------------------------------------------
|
| Ashaiman opens at 10:00 and closes at 22:00. Its manager may start the
| checklist from 08:00. Nothing sells until it is done, except an online order
| placed in the first half hour after ten, which waits for the till. Problems
| can be admitted; food safety cannot. Head office hears about lateness and
| problems by text, and can open the branch without the checklist.
|
*/

beforeEach(function () {
    Notification::fake();
    Storage::fake('public');
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    config()->set('app.frontend_url', 'https://app.cedibites.com');
    app(RuntimeSettings::class)->set('alerts.admin_phones', '0592123054, +233 50 392 3322');

    // Friday 25 September 2026, before anyone is in.
    $this->travelTo(Carbon::parse('2026-09-25 07:30:00'));

    $this->branch = opBranch('Ashaiman');
    $this->manager = opStaff('manager', $this->branch, 'Kofi Mensah');
});

function opBranch(string $name, bool $required = true): Branch
{
    // Extended access on, as every branch is on prod: the till would sell at
    // any hour today, which is exactly what the checklist has to stop.
    $branch = Branch::factory()->create([
        'name' => $name,
        'is_active' => true,
        'extended_staff_access' => true,
        'extended_order_access' => true,
        'requires_opening_checklist' => $required,
    ]);
    $branch->operatingHours()->update(['open_time' => '10:00', 'close_time' => '22:00', 'is_open' => true]);

    return $branch->fresh();
}

function opStaff(string $role, ?Branch $branch = null, ?string $name = null): User
{
    $user = User::factory()->create($name ? ['name' => $name] : []);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => EmployeeStatus::Active]);
    if ($branch) {
        $employee->branches()->attach($branch);
    }
    $user->syncRoles([$role]);

    return $user->fresh();
}

function opDish(Branch $branch): MenuItem
{
    $dish = MenuItem::factory()->create(['branch_id' => $branch->id, 'name' => 'Jollof']);
    $dish->branches()->attach($branch->id, ['is_available' => true]);

    return $dish->fresh(['options']);
}

function opSale(Branch $branch, MenuItem $dish, array $extra = []): array
{
    return $extra + [
        'branch_id' => $branch->id,
        'items' => [[
            'menu_item_id' => $dish->id,
            'menu_item_option_id' => $dish->options->first()->id,
            'quantity' => 1,
            'unit_price' => 20,
        ]],
        'payment_method' => 'cash',
        'fulfillment_type' => 'takeaway',
        'contact_name' => 'Ama',
        'contact_phone' => '+233541234567',
    ];
}

function opStart(Branch $branch, User $manager): BranchOpening
{
    return app(BranchOpeningService::class)->start($branch, $manager, 'pos');
}

/**
 * Answer every line "yes", with numbers where numbers are asked, then apply
 * the given answers by key through the service, so they are validated.
 */
function opAnswerAll(BranchOpening $opening, User $manager, array $overrides = []): void
{
    BranchOpeningAnswer::where('branch_opening_id', $opening->id)->where('kind', 'check')
        ->update(['answer' => 'ok', 'answered_by' => $manager->id, 'answered_at' => now()]);
    BranchOpeningAnswer::where('branch_opening_id', $opening->id)->where('kind', 'number')
        ->update(['value' => '6', 'answered_by' => $manager->id, 'answered_at' => now()]);

    foreach ($overrides as $key => $data) {
        $answer = BranchOpeningAnswer::where('branch_opening_id', $opening->id)->where('key', $key)->sole();
        app(BranchOpeningService::class)->answer($answer, $manager, $data);
    }
}

/** What head office was texted, in order. */
function opTexts(): Collection
{
    return ActivityLog::where('log_name', 'alerts')->orderBy('id')->get()->map(fn ($a) => $a->properties['message']);
}

/*
|--------------------------------------------------------------------------
| Nothing changes for a branch not switched on
|--------------------------------------------------------------------------
*/

it('leaves a branch that does not use the checklist exactly as it was', function () {
    $other = opBranch('Lakeside', required: false);
    $cashier = opStaff('sales_staff', $other);
    $this->travelTo(Carbon::parse('2026-09-25 07:45:00'));

    $this->actingAs($cashier)->postJson('/v1/pos/checkout-sessions', opSale($other, opDish($other)))->assertSuccessful();

    expect($this->getJson("/v1/branches/{$other->id}")->json('data.opening'))
        ->toMatchArray(['required' => false, 'opened' => true, 'getting_ready' => false]);
});

/*
|--------------------------------------------------------------------------
| The till
|--------------------------------------------------------------------------
*/

it('refuses a sale at the till until the branch is opened', function () {
    $cashier = opStaff('sales_staff', $this->branch);
    $this->travelTo(Carbon::parse('2026-09-25 10:05:00'));

    $this->actingAs($cashier)
        ->postJson('/v1/pos/checkout-sessions', opSale($this->branch, opDish($this->branch)))
        ->assertStatus(422)
        ->assertJsonPath('code', 'branch_not_opened');
});

it('refuses the call centre too, since it uses the same door', function () {
    $agent = opStaff('call_center');
    $this->travelTo(Carbon::parse('2026-09-25 10:05:00'));

    $this->actingAs($agent)
        ->postJson('/v1/pos/checkout-sessions', opSale($this->branch, opDish($this->branch), ['order_source' => 'phone', 'fulfillment_type' => 'delivery']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'branch_not_opened');
});

it('sells from the moment the branch is opened, even before ten', function () {
    $this->travelTo(Carbon::parse('2026-09-25 09:30:00'));
    $opening = opStart($this->branch, $this->manager);
    opAnswerAll($opening, $this->manager);
    app(BranchOpeningService::class)->complete($opening, $this->manager, 'pos');

    // No extended order access, so only the early opening lets this through.
    $this->branch->update(['extended_order_access' => false, 'extended_staff_access' => false]);
    $cashier = opStaff('sales_staff', $this->branch);

    $this->actingAs($cashier)->postJson('/v1/pos/checkout-sessions', opSale($this->branch, opDish($this->branch)))->assertSuccessful();
});

it('still takes a paper sale entered afterwards, with the reason for it', function () {
    $cashier = opStaff('sales_staff', $this->branch);
    $this->travelTo(Carbon::parse('2026-09-25 11:00:00'));

    $sale = opSale($this->branch, opDish($this->branch), [
        'is_manual_entry' => true,
        'recorded_at' => now()->subHour()->toIso8601String(),
    ]);

    $this->actingAs($cashier)->postJson('/v1/pos/checkout-sessions', $sale)
        ->assertStatus(422)
        ->assertJsonValidationErrors('manual_entry_reason');

    $this->actingAs($cashier)
        ->postJson('/v1/pos/checkout-sessions', $sale + ['manual_entry_reason' => 'Power was off from 9 until 10.30'])
        ->assertSuccessful();

    expect(\App\Models\CheckoutSession::latest('id')->first()->manual_entry_reason)->toBe('Power was off from 9 until 10.30');
});

/*
|--------------------------------------------------------------------------
| Online
|--------------------------------------------------------------------------
*/

it('takes an online order just after ten while the manager finishes, and stops once the branch is late', function () {
    $service = app(BranchOpeningService::class);

    $this->travelTo(Carbon::parse('2026-09-25 10:05:00'));
    expect($service->refusalForOnline($this->branch))->toBeNull()
        ->and($this->getJson("/v1/branches/{$this->branch->id}")->json('data.opening.getting_ready'))->toBeTrue();

    $this->travelTo(Carbon::parse('2026-09-25 10:40:00'));
    expect($service->refusalForOnline($this->branch)?->reason)->toBe('branch_not_opened')
        ->and($this->getJson("/v1/branches/{$this->branch->id}")->json('data.opening.getting_ready'))->toBeFalse();

    $opening = opStart($this->branch, $this->manager);
    opAnswerAll($opening, $this->manager);
    $service->complete($opening, $this->manager, 'pos');

    expect($service->refusalForOnline($this->branch))->toBeNull()
        ->and($this->getJson("/v1/branches/{$this->branch->id}")->json('data.opening'))
        ->toMatchArray(['opened' => true, 'status' => 'open']);
});

it('tells customers nothing about who or what went wrong', function () {
    $this->travelTo(Carbon::parse('2026-09-25 09:00:00'));
    $opening = opStart($this->branch, $this->manager);
    opAnswerAll($opening, $this->manager, ['gas' => ['answer' => 'problem', 'note' => 'One cylinder left']]);
    app(BranchOpeningService::class)->complete($opening, $this->manager, 'pos');

    $public = json_encode($this->getJson("/v1/branches/{$this->branch->id}")->json('data.opening'));

    expect($public)->not->toContain('Kofi')->not->toContain('cylinder')->not->toContain('gas');
});

/*
|--------------------------------------------------------------------------
| Staff getting in to prepare
|--------------------------------------------------------------------------
*/

it('lets staff sign in from two hours before opening', function () {
    $this->branch->update(['extended_staff_access' => false, 'extended_order_access' => false]);

    expect($this->branch->fresh()->isStaffAccessAllowed())->toBeFalse();

    $this->travelTo(Carbon::parse('2026-09-25 08:00:00'));
    expect($this->branch->fresh()->isStaffAccessAllowed())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The checklist
|--------------------------------------------------------------------------
*/

it('opens the checklist at eight and not before', function () {
    $this->actingAs($this->manager)
        ->postJson("/v1/manager/branches/{$this->branch->id}/opening", ['via' => 'pos'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'too_early')
        ->assertJsonPath('message', 'The checklist opens at 8:00 am.');

    $this->travelTo(Carbon::parse('2026-09-25 08:05:00'));

    $data = $this->actingAs($this->manager)
        ->postJson("/v1/manager/branches/{$this->branch->id}/opening", ['via' => 'pos'])
        ->assertSuccessful()
        ->json('data');

    expect($data['status'])->toBe('in_progress')
        ->and($data['started_by'])->toBe('Kofi Mensah')
        ->and($data['answers'])->toHaveCount(OpeningChecklistItem::active()->count())
        ->and(collect($data['answers'])->pluck('section')->unique()->values()->all())->toBe([
            'Staffing and team readiness',
            'Stock levels and product availability',
            'Facility and operational readiness',
        ]);
});

it('keeps one opening per branch per day, however many tills start it', function () {
    $this->travelTo(Carbon::parse('2026-09-25 08:30:00'));
    opStart($this->branch, $this->manager);
    opStart($this->branch, $this->manager);

    expect(BranchOpening::count())->toBe(1)
        ->and(BranchOpeningAnswer::count())->toBe(OpeningChecklistItem::active()->count());
});

it('wants to know what the problem is', function () {
    $this->travelTo(Carbon::parse('2026-09-25 08:30:00'));
    $opening = opStart($this->branch, $this->manager);
    $gas = $opening->answers->firstWhere('key', 'gas');

    $this->actingAs($this->manager)
        ->patchJson("/v1/manager/branches/{$this->branch->id}/opening/answers/{$gas->id}", ['answer' => 'problem'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Say what the problem is.');

    $this->actingAs($this->manager)
        ->patchJson("/v1/manager/branches/{$this->branch->id}/opening/answers/{$gas->id}", ['answer' => 'problem', 'note' => 'One cylinder left'])
        ->assertSuccessful()
        ->assertJsonPath('data.answer', 'problem');
});

it('offers not applicable only where the checklist says so', function () {
    $this->travelTo(Carbon::parse('2026-09-25 08:30:00'));
    $opening = opStart($this->branch, $this->manager);
    $gas = $opening->answers->firstWhere('key', 'gas');
    $backup = $opening->answers->firstWhere('key', 'backup_power');

    $url = fn ($a) => "/v1/manager/branches/{$this->branch->id}/opening/answers/{$a->id}";

    $this->actingAs($this->manager)->patchJson($url($gas), ['answer' => 'na'])->assertStatus(422);
    $this->actingAs($this->manager)->patchJson($url($backup), ['answer' => 'na'])->assertSuccessful();
});

it('will not open with a line unanswered', function () {
    $this->travelTo(Carbon::parse('2026-09-25 08:30:00'));
    $opening = opStart($this->branch, $this->manager);
    opAnswerAll($opening, $this->manager);
    BranchOpeningAnswer::where('branch_opening_id', $opening->id)->whereIn('key', ['gas', 'water'])->update(['answer' => null]);

    $this->actingAs($this->manager)
        ->postJson("/v1/manager/branches/{$this->branch->id}/opening/complete")
        ->assertStatus(422)
        ->assertJsonPath('code', 'checklist_incomplete')
        ->assertJsonPath('message', '2 lines still need an answer.')
        ->assertJsonCount(2, 'unanswered');
});

it('will not open with a food-safety problem', function () {
    $this->travelTo(Carbon::parse('2026-09-25 09:00:00'));
    $opening = opStart($this->branch, $this->manager);
    opAnswerAll($opening, $this->manager, ['no_pests' => ['answer' => 'problem', 'note' => 'Droppings behind the fridge']]);

    $this->actingAs($this->manager)
        ->postJson("/v1/manager/branches/{$this->branch->id}/opening/complete")
        ->assertStatus(422)
        ->assertJsonPath('code', 'food_safety');

    expect($opening->fresh()->isOpen())->toBeFalse()
        ->and(app(BranchOpeningService::class)->refusalForTill($this->branch))->not->toBeNull();
});

it('opens quietly when everything is ready', function () {
    $this->travelTo(Carbon::parse('2026-09-25 09:40:00'));
    $opening = opStart($this->branch, $this->manager);
    opAnswerAll($opening, $this->manager);

    $data = $this->actingAs($this->manager)
        ->postJson("/v1/manager/branches/{$this->branch->id}/opening/complete", ['via' => 'pos'])
        ->assertSuccessful()
        ->json('data');

    expect($data['status'])->toBe('open')
        ->and($data['opened_by'])->toBe('Kofi Mensah')
        ->and($data['opened_via'])->toBe('pos')
        ->and($data['is_late'])->toBeFalse()
        // A good morning is not news.
        ->and(opTexts())->toBeEmpty()
        ->and(ActivityLog::where('event', 'branch_opened')->count())->toBe(1);
});

it('opens with an admitted problem, texts head office, and gives one hour to fix it', function () {
    $this->travelTo(Carbon::parse('2026-09-25 09:52:00'));
    $opening = opStart($this->branch, $this->manager);
    opAnswerAll($opening, $this->manager, [
        'gas' => ['answer' => 'problem', 'note' => 'One cylinder left, another ordered'],
        'washrooms' => ['answer' => 'problem', 'note' => 'No water in the tank'],
    ]);

    $data = $this->actingAs($this->manager)
        ->postJson("/v1/manager/branches/{$this->branch->id}/opening/complete")
        ->assertSuccessful()
        ->json('data');

    expect($data['status'])->toBe('open_with_problems')
        ->and($data['problems'])->toMatchArray(['total' => 2, 'outstanding' => 2])
        ->and(Carbon::parse($data['grace_ends_at'])->format('H:i'))->toBe('10:52')
        ->and(opTexts()->all())->toBe([
            "CediBites: Ashaiman opened at 9:52 am with 2 problems: gas and washrooms. Kofi has until 10:52 am to fix them. https://app.cedibites.com/admin/openings/{$opening->id}",
        ]);

    Notification::assertSentOnDemand(AdminAlertNotification::class, function ($n, $channels, AnonymousNotifiable $to) {
        return $to->routeNotificationFor(\App\Channels\SmsChannel::class) === '+233592123054';
    });
    Notification::assertSentOnDemandTimes(AdminAlertNotification::class, 2);
});

it('closes the matter with one text when every problem is fixed', function () {
    $this->travelTo(Carbon::parse('2026-09-25 09:52:00'));
    $opening = opStart($this->branch, $this->manager);
    opAnswerAll($opening, $this->manager, ['gas' => ['answer' => 'problem', 'note' => 'One cylinder left']]);
    app(BranchOpeningService::class)->complete($opening, $this->manager, 'pos');
    $gas = $opening->answers()->where('key', 'gas')->sole();

    $this->travelTo(Carbon::parse('2026-09-25 10:31:00'));
    $url = "/v1/manager/branches/{$this->branch->id}/opening/answers/{$gas->id}/resolve";

    $this->actingAs($this->manager)->postJson($url, ['note' => ''])->assertStatus(422);
    $this->actingAs($this->manager)->postJson($url, ['note' => 'New cylinder delivered and connected'])
        ->assertSuccessful()
        ->assertJsonPath('data.resolved_by', 'Kofi Mensah');

    expect(opTexts()->last())->toBe("CediBites: Ashaiman: the gas problem is fixed (10:31 am). https://app.cedibites.com/admin/openings/{$opening->id}")
        ->and(app(BranchOpeningService::class)->status($this->branch))->toBe('open');
});

it('locks the answers once the branch is open', function () {
    $this->travelTo(Carbon::parse('2026-09-25 09:30:00'));
    $opening = opStart($this->branch, $this->manager);
    opAnswerAll($opening, $this->manager);
    app(BranchOpeningService::class)->complete($opening, $this->manager, 'pos');
    $gas = $opening->answers()->where('key', 'gas')->sole();

    $this->actingAs($this->manager)
        ->patchJson("/v1/manager/branches/{$this->branch->id}/opening/answers/{$gas->id}", ['answer' => 'problem', 'note' => 'late'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'checklist_completed');
});

/*
|--------------------------------------------------------------------------
| Late, and not fixed
|--------------------------------------------------------------------------
*/

it('tells head office at 10:15 that the branch has not opened, reminds once, then stops', function () {
    $this->travelTo(Carbon::parse('2026-09-25 10:14:00'));
    $this->artisan('openings:watch');
    expect(opTexts())->toBeEmpty();

    $this->travelTo(Carbon::parse('2026-09-25 10:16:00'));
    $this->artisan('openings:watch');
    $this->travelTo(Carbon::parse('2026-09-25 10:21:00'));
    $this->artisan('openings:watch');

    expect(opTexts()->all())->toBe([
        'CediBites: Ashaiman has not opened. It was due at 10:00 am. Nobody has started the checklist. https://app.cedibites.com/admin/openings/'.BranchOpening::sole()->id,
    ]);

    $this->travelTo(Carbon::parse('2026-09-25 11:17:00'));
    $this->artisan('openings:watch');
    $this->travelTo(Carbon::parse('2026-09-25 13:00:00'));
    $this->artisan('openings:watch');

    expect(opTexts())->toHaveCount(2)
        ->and(opTexts()->last())->toStartWith('CediBites: Ashaiman is still not open at 11:17 am. It was due at 10:00 am.');
});

it('says how far the manager got when the branch is late', function () {
    $this->travelTo(Carbon::parse('2026-09-25 09:58:00'));
    $opening = opStart($this->branch, $this->manager);
    BranchOpeningAnswer::where('branch_opening_id', $opening->id)->where('kind', 'check')->limit(40)->update(['answer' => 'ok']);

    $this->travelTo(Carbon::parse('2026-09-25 10:16:00'));
    $this->artisan('openings:watch');

    expect(opTexts()->sole())->toMatch('/Kofi started the checklist at 9:58 am and has answered \d+ of 60\./');
});

it('tells head office when a late branch finally opens', function () {
    $this->travelTo(Carbon::parse('2026-09-25 10:16:00'));
    $this->artisan('openings:watch');

    $this->travelTo(Carbon::parse('2026-09-25 10:40:00'));
    $opening = opStart($this->branch, $this->manager);
    opAnswerAll($opening, $this->manager);
    app(BranchOpeningService::class)->complete($opening, $this->manager, 'pos');

    expect(opTexts()->last())->toStartWith('CediBites: Ashaiman opened at 10:40 am, 40 minutes late.');
});

it('texts once when the hour is up, reminds every three hours, and stops at closing', function () {
    $this->travelTo(Carbon::parse('2026-09-25 09:52:00'));
    $opening = opStart($this->branch, $this->manager);
    opAnswerAll($opening, $this->manager, ['gas' => ['answer' => 'problem', 'note' => 'One cylinder left']]);
    app(BranchOpeningService::class)->complete($opening, $this->manager, 'pos');

    foreach (['10:50', '10:53', '11:30', '13:54', '17:00', '19:55', '22:30'] as $time) {
        $this->travelTo(Carbon::parse("2026-09-25 {$time}:00"));
        $this->artisan('openings:watch');
    }

    // Grace ends 10:52: told at 10:53, reminded at 13:54 and 17:00, and not
    // again after 22:00 closing.
    $after = opTexts()->slice(1)->values();

    expect($after)->toHaveCount(3)
        ->and($after[0])->toStartWith('CediBites: Ashaiman still has 1 problem an hour after opening: gas.')
        ->and($after[1])->toStartWith('CediBites: Reminder. Ashaiman still has 1 problem from this morning: gas.')
        ->and($after[2])->toStartWith('CediBites: Reminder. Ashaiman still has 1 problem from this morning: gas.');
});

/*
|--------------------------------------------------------------------------
| Head office
|--------------------------------------------------------------------------
*/

it('lets head office open a branch without the checklist, with a reason, on the record', function () {
    $admin = opStaff('admin', null, 'Richard Somda');
    $this->travelTo(Carbon::parse('2026-09-25 10:20:00'));

    $url = "/v1/admin/branches/{$this->branch->id}/open-without-checklist";
    $this->actingAs($admin)->postJson($url, ['reason' => 'sick'])->assertStatus(422);

    $data = $this->actingAs($admin)->postJson($url, ['reason' => 'Manager is at the hospital with a sick child.'])
        ->assertSuccessful()
        ->json('data');

    expect($data['status'])->toBe('opened_by_head_office')
        ->and($data['is_override'])->toBeTrue()
        ->and(app(BranchOpeningService::class)->refusalForTill($this->branch))->toBeNull()
        ->and(opTexts()->last())->toStartWith('CediBites: Richard Somda opened Ashaiman without the checklist. Reason: Manager is at the hospital with a sick child.');

    $entry = ActivityLog::where('log_name', 'openings')->where('event', 'branch_opened_without_checklist')->sole();
    expect($entry->properties['unusual'])->toBeTrue()
        ->and($entry->causer_id)->toBe($admin->id);
});

it('still has the manager complete the checklist after head office opened it', function () {
    $admin = opStaff('admin');
    $this->travelTo(Carbon::parse('2026-09-25 10:20:00'));
    app(BranchOpeningService::class)->openByHeadOffice($this->branch, $admin, 'Manager stuck in traffic on the motorway.');

    $this->travelTo(Carbon::parse('2026-09-25 10:50:00'));
    $opening = opStart($this->branch, $this->manager);
    opAnswerAll($opening, $this->manager);
    $done = app(BranchOpeningService::class)->complete($opening, $this->manager, 'portal');

    expect($done->opened_by)->toBe($admin->id)
        ->and($done->completed_by)->toBe($this->manager->id)
        ->and(app(BranchOpeningService::class)->status($this->branch))->toBe('open');
});

it('shows head office every branch and how it started the day', function () {
    $admin = opStaff('admin');
    opBranch('Lakeside', required: false);
    $this->travelTo(Carbon::parse('2026-09-25 10:20:00'));

    $rows = collect($this->actingAs($admin)->getJson('/v1/admin/openings')->assertSuccessful()->json('data.branches'))->keyBy('branch.name');

    expect($rows['Ashaiman'])->toMatchArray(['status' => 'not_started', 'is_late' => true, 'requires_opening_checklist' => true])
        ->and($rows['Lakeside'])->toMatchArray(['status' => 'not_required', 'requires_opening_checklist' => false]);
});

it('lets head office switch a branch onto the checklist, on the record', function () {
    $admin = opStaff('admin');
    $other = opBranch('Lakeside', required: false);

    $this->actingAs($admin)->patchJson("/v1/admin/branches/{$other->id}/opening-requirement", ['required' => true])
        ->assertSuccessful()
        ->assertJsonPath('data.requires_opening_checklist', true);

    expect(ActivityLog::where('subject_type', Branch::class)->where('subject_id', $other->id)->latest('id')->first()->properties['attributes'])
        ->toHaveKey('requires_opening_checklist', true);
});

it('changes tomorrow\'s checklist without rewriting today\'s', function () {
    $admin = opStaff('admin');
    $this->travelTo(Carbon::parse('2026-09-25 08:30:00'));
    opStart($this->branch, $this->manager);
    $gas = OpeningChecklistItem::where('key', 'gas')->sole();

    $this->actingAs($admin)->patchJson("/v1/admin/opening-checklist/{$gas->id}", [
        'label' => 'Two full gas cylinders connected.',
        'weight' => 'must_pass',
    ])->assertSuccessful();

    expect(BranchOpeningAnswer::where('key', 'gas')->sole()->label)->toBe('Gas supply checked and adequate.');

    $this->travelTo(Carbon::parse('2026-09-26 08:30:00'));
    $tomorrow = opStart($this->branch, $this->manager);
    expect($tomorrow->answers->firstWhere('key', 'gas'))
        ->label->toBe('Two full gas cylinders connected.')
        ->weight->toBe('must_pass');
});

/*
|--------------------------------------------------------------------------
| Who may do what
|--------------------------------------------------------------------------
*/

it('keeps a manager to their own branch', function () {
    $other = opBranch('East Legon');
    $this->travelTo(Carbon::parse('2026-09-25 08:30:00'));

    $this->actingAs($this->manager)->postJson("/v1/manager/branches/{$other->id}/opening")->assertForbidden();
});

it('lets a cashier see where the opening stands, and not do it', function () {
    $cashier = opStaff('sales_staff', $this->branch);
    $this->travelTo(Carbon::parse('2026-09-25 08:30:00'));
    opStart($this->branch, $this->manager);

    $this->actingAs($cashier)->postJson("/v1/manager/branches/{$this->branch->id}/opening")->assertForbidden();

    $status = $this->actingAs($cashier)->getJson("/v1/employee/branches/{$this->branch->id}/opening")
        ->assertSuccessful()
        ->json('data');

    expect($status['status'])->toBe('in_progress')
        ->and($status['started_by'])->toBe('Kofi Mensah')
        ->and($status['answers'])->toBe([]);
});

it('does not let a line from another day be answered', function () {
    $this->travelTo(Carbon::parse('2026-09-25 08:30:00'));
    $yesterday = opStart($this->branch, $this->manager);
    $line = $yesterday->answers->first();

    $this->travelTo(Carbon::parse('2026-09-26 08:30:00'));

    $this->actingAs($this->manager)
        ->patchJson("/v1/manager/branches/{$this->branch->id}/opening/answers/{$line->id}", ['answer' => 'ok'])
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Photos
|--------------------------------------------------------------------------
*/

it('keeps a photo of the problem and a photo of the fix apart', function () {
    $this->travelTo(Carbon::parse('2026-09-25 09:30:00'));
    $opening = opStart($this->branch, $this->manager);
    opAnswerAll($opening, $this->manager, ['gas' => ['answer' => 'problem', 'note' => 'One cylinder left']]);
    $gas = $opening->answers()->where('key', 'gas')->sole();
    $photos = "/v1/manager/branches/{$this->branch->id}/opening/answers/{$gas->id}/photos";

    $this->actingAs($this->manager)->post($photos, ['file' => UploadedFile::fake()->image('gas.jpg')])->assertCreated();

    app(BranchOpeningService::class)->complete($opening, $this->manager, 'pos');
    app(BranchOpeningService::class)->resolve($gas->fresh(), $this->manager, 'Second cylinder connected');

    $this->actingAs($this->manager)->post($photos, ['file' => UploadedFile::fake()->image('fixed.jpg')])->assertCreated();

    expect($gas->photos()->pluck('stage')->all())->toBe(['reported', 'fixed']);
    Storage::disk('public')->assertExists($gas->photos()->first()->path);
});

it('lets only the person who took a photo remove it', function () {
    $this->travelTo(Carbon::parse('2026-09-25 09:30:00'));
    $opening = opStart($this->branch, $this->manager);
    $gas = $opening->answers->firstWhere('key', 'gas');
    $photo = app(BranchOpeningService::class)->attachPhoto($gas, UploadedFile::fake()->image('gas.jpg'), $this->manager);

    $deputy = opStaff('manager', $this->branch);
    $this->actingAs($deputy)->deleteJson("/v1/manager/branches/{$this->branch->id}/opening/photos/{$photo->id}")->assertStatus(422);
    $this->actingAs($this->manager)->deleteJson("/v1/manager/branches/{$this->branch->id}/opening/photos/{$photo->id}")->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| Safety
|--------------------------------------------------------------------------
*/

it('lets every branch sell again when the switch in the settings panel is pulled', function () {
    $this->travelTo(Carbon::parse('2026-09-25 10:05:00'));
    app(RuntimeSettings::class)->set('openings.enforced', false);

    expect(app(BranchOpeningService::class)->refusalForTill($this->branch))->toBeNull();
});

it('never stops a sale because the check itself broke', function () {
    $this->travelTo(Carbon::parse('2026-09-25 10:05:00'));
    Log::spy();

    $service = Mockery::mock(BranchOpeningService::class, [app(RuntimeSettings::class), app(\App\Services\Openings\OpeningAlerts::class)])->makePartial();
    $service->shouldReceive('current')->andThrow(new RuntimeException('database hiccup'));

    expect($service->refusalForTill($this->branch))->toBeNull();
    Log::shouldHaveReceived('critical')->withArgs(fn ($message) => str_contains($message, 'letting the branch trade'));
});

/*
|--------------------------------------------------------------------------
| Head office's alert numbers
|--------------------------------------------------------------------------
*/

it('refuses a mistyped alert number instead of quietly dropping it', function () {
    $tech = opStaff('tech_admin');

    $this->actingAs($tech)->putJson('/v1/platform/settings', ['key' => 'alerts.admin_phones', 'value' => '0592123054, 05921'])
        ->assertStatus(422)
        ->assertJsonPath('message', '05921 is not a Ghana mobile number.');

    // A number written with spaces is still one number.
    $this->actingAs($tech)->putJson('/v1/platform/settings', ['key' => 'alerts.admin_phones', 'value' => "0592123054\n+233 50 392 3322"])
        ->assertSuccessful()
        ->assertJsonPath('data.value', '+233592123054, +233503923322');
});

it('records an alert even when there is nobody to text', function () {
    app(RuntimeSettings::class)->revert('alerts.admin_phones');
    config()->set('alerts.admin_phones', '');

    $this->travelTo(Carbon::parse('2026-09-25 10:16:00'));
    $this->artisan('openings:watch');

    expect(ActivityLog::where('log_name', 'alerts')->sole()->properties['recipients'])->toBe(0);
    Notification::assertNothingSent();
});

it('texts head office when someone asks to cancel an order', function () {
    $cashier = opStaff('sales_staff', $this->branch, 'Rosina Owusu');
    $order = \App\Models\Order::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => 'received',
        'order_number' => 'A123',
        'total_amount' => 85,
    ]);

    $this->actingAs($cashier)->postJson("/v1/employee/orders/{$order->id}/request-cancel", ['reason' => 'Customer changed their mind'])
        ->assertSuccessful();

    expect(opTexts()->last())->toBe('CediBites: Rosina Owusu asks to cancel order #A123 at Ashaiman (GHS 85.00): Customer changed their mind. https://app.cedibites.com/admin/orders/cancel-requests');
});

/*
|--------------------------------------------------------------------------
| Questions that depend on other answers
|--------------------------------------------------------------------------
*/

/** Whether each line is asked, by key, as the manager's screen is told. */
function opAsked(Branch $branch, User $manager): array
{
    return collect(test()->actingAs($manager)->getJson("/v1/manager/branches/{$branch->id}/opening")->json('data.answers'))
        ->mapWithKeys(fn ($a) => [$a['key'] => $a['relevant']])
        ->all();
}

it('does not ask about cover for absent staff once everybody has reported', function () {
    $this->travelTo(Carbon::parse('2026-09-25 08:30:00'));
    $opening = opStart($this->branch, $this->manager);
    $reported = $opening->answers->firstWhere('key', 'staff_reported');

    app(BranchOpeningService::class)->answer($reported, $this->manager, ['answer' => 'ok']);
    expect(opAsked($this->branch, $this->manager))
        ->absences_covered->toBeFalse()
        ->staff_absent_late->toBeFalse();

    app(BranchOpeningService::class)->answer($reported, $this->manager, ['answer' => 'problem', 'note' => 'Cilla is not in yet']);
    expect(opAsked($this->branch, $this->manager))
        ->absences_covered->toBeTrue()
        ->staff_absent_late->toBeTrue();
});

it('opens without the lines nobody was asked, and clears any stale answer to them', function () {
    $this->travelTo(Carbon::parse('2026-09-25 09:30:00'));
    $opening = opStart($this->branch, $this->manager);
    opAnswerAll($opening, $this->manager);
    // Answered while somebody was missing, then everybody turned up.
    BranchOpeningAnswer::where('branch_opening_id', $opening->id)
        ->whereIn('key', ['low_stock_identified', 'critical_sufficient', 'out_of_stock_reported', 'replenishment_requested'])
        ->update(['answer' => null]);
    BranchOpeningAnswer::where('branch_opening_id', $opening->id)->where('key', 'absences_covered')
        ->update(['answer' => 'problem', 'note' => 'Stale']);

    $done = app(BranchOpeningService::class)->complete($opening, $this->manager, 'pos');

    expect($done->isOpen())->toBeTrue()
        ->and($done->problems())->toBeEmpty()
        ->and(BranchOpeningAnswer::where('branch_opening_id', $opening->id)->where('key', 'absences_covered')->sole()->answer)->toBeNull()
        // Nothing to text about: the stale problem was never asked.
        ->and(opTexts())->toBeEmpty();
});

it('asks the stock follow-ups only when something is short', function () {
    $this->travelTo(Carbon::parse('2026-09-25 08:30:00'));
    $opening = opStart($this->branch, $this->manager);
    opAnswerAll($opening, $this->manager);

    expect(opAsked($this->branch, $this->manager))
        ->low_stock_identified->toBeFalse()
        ->low_stock_notes->toBeFalse()
        ->staffing_notes->toBeFalse();

    $rice = $opening->answers()->where('key', 'rice')->sole();
    app(BranchOpeningService::class)->answer($rice, $this->manager, ['answer' => 'problem', 'note' => 'Two bags left']);
    BranchOpeningAnswer::where('branch_opening_id', $opening->id)->where('key', 'low_stock_identified')->update(['answer' => null]);

    expect(opAsked($this->branch, $this->manager))
        ->low_stock_identified->toBeTrue()
        ->critical_sufficient->toBeTrue()
        ->low_stock_notes->toBeTrue()
        ->staffing_notes->toBeFalse();

    // And now it has to be answered before the branch can open.
    $this->actingAs($this->manager)->postJson("/v1/manager/branches/{$this->branch->id}/opening/complete")
        ->assertStatus(422)
        ->assertJsonPath('code', 'checklist_incomplete');
});

it('counts only the lines still asked', function () {
    $this->travelTo(Carbon::parse('2026-09-25 08:30:00'));
    opStart($this->branch, $this->manager);

    $progress = $this->actingAs($this->manager)->getJson("/v1/manager/branches/{$this->branch->id}/opening")->json('data.progress');

    // 66 lines can need an answer; six of them only once something is wrong.
    expect($progress)->toBe(['answered' => 0, 'total' => 60]);
});

/*
|--------------------------------------------------------------------------
| Yes to all
|--------------------------------------------------------------------------
*/

it('answers a whole set Yes in one tap, keeping any problem already admitted', function () {
    $this->travelTo(Carbon::parse('2026-09-25 08:30:00'));
    $opening = opStart($this->branch, $this->manager);
    $gas = $opening->answers->firstWhere('key', 'gas');
    app(BranchOpeningService::class)->answer($gas, $this->manager, ['answer' => 'problem', 'note' => 'One cylinder left']);

    $saved = $this->actingAs($this->manager)->postJson("/v1/manager/branches/{$this->branch->id}/opening/answer-group", [
        'section' => 'Facility and operational readiness',
        'group' => 'Kitchen',
    ])->assertSuccessful()->json('data');

    $kitchen = BranchOpeningAnswer::where('branch_opening_id', $opening->id)->where('group', 'Kitchen')->pluck('answer', 'key');

    expect(collect($saved)->pluck('key'))->not->toContain('gas')
        ->and($kitchen['gas'])->toBe('problem')
        ->and($kitchen->except('gas')->unique()->values()->all())->toBe(['ok']);
});

it('does not answer the lines a Yes makes moot', function () {
    $this->travelTo(Carbon::parse('2026-09-25 08:30:00'));
    $opening = opStart($this->branch, $this->manager);

    $this->actingAs($this->manager)->postJson("/v1/manager/branches/{$this->branch->id}/opening/answer-group", [
        'section' => 'Staffing and team readiness',
        'group' => 'Attendance',
    ])->assertSuccessful();

    $attendance = BranchOpeningAnswer::where('branch_opening_id', $opening->id)->where('group', 'Attendance')->pluck('answer', 'key');

    expect($attendance['staff_reported'])->toBe('ok')
        ->and($attendance['enough_staff'])->toBe('ok')
        // Everybody reported, so nobody was asked about cover.
        ->and($attendance['absences_covered'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Resetting for testing
|--------------------------------------------------------------------------
*/

it('lets head office throw away the day\'s opening on beta, so the morning can be run again', function () {
    $admin = opStaff('admin');
    $this->travelTo(Carbon::parse('2026-09-25 09:30:00'));
    $opening = opStart($this->branch, $this->manager);
    opAnswerAll($opening, $this->manager);
    app(BranchOpeningService::class)->complete($opening, $this->manager, 'pos');
    expect(app(BranchOpeningService::class)->refusalForTill($this->branch))->toBeNull()
        ->and($this->actingAs($admin)->getJson('/v1/admin/openings')->json('data.can_reset'))->toBeTrue();

    $this->actingAs($admin)->postJson("/v1/admin/branches/{$this->branch->id}/opening/reset")->assertSuccessful();

    expect(BranchOpening::count())->toBe(0)
        ->and(app(BranchOpeningService::class)->refusalForTill($this->branch))->not->toBeNull()
        ->and(ActivityLog::where('event', 'opening_reset')->count())->toBe(1);
});

it('refuses the reset in production', function () {
    config()->set('openings.allow_reset', false);
    $admin = opStaff('admin');
    $this->travelTo(Carbon::parse('2026-09-25 09:30:00'));
    opStart($this->branch, $this->manager);

    $this->actingAs($admin)->postJson("/v1/admin/branches/{$this->branch->id}/opening/reset")->assertForbidden();
    expect(BranchOpening::count())->toBe(1);
});
