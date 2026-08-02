<?php

use App\Enums\EmployeeStatus;
use App\Enums\RecruitmentApplicationStatus;
use App\Enums\RecruitmentLinkKind;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentLink;
use App\Models\User;
use App\Notifications\StaffApplicationApprovedNotification;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Recruitment
|--------------------------------------------------------------------------
|
| One form, filled in by a member of the public, that becomes a staff account
| only when somebody approves it. What these pin down:
|
|   · A submission creates nothing that can log in.
|   · The recruit never chooses their role or their branch.
|   · A branch link stamps its branch; a call-centre link stamps nothing, and
|     that emptiness is what User::isCompanyWide() reads.
|   · A closed link says so, and says the same thing whether it expired or
|     never existed.
|   · Two reviewers cannot hire the same person twice.
|   · A manager recruits for their own branch and reviews only their own.
|
*/

function recruitStaff(string $role, ?Branch $branch = null): array
{
    $user = User::factory()->create();
    $employee = Employee::factory()->create([
        'user_id' => $user->id,
        'status' => EmployeeStatus::Active,
    ]);

    if ($branch) {
        $employee->branches()->attach($branch);
    }

    $user->syncRoles([$role]);

    return ['user' => $user->fresh(), 'employee' => $employee];
}

function branchLink(Branch $branch, User $creator, array $attributes = []): RecruitmentLink
{
    return RecruitmentLink::create([
        'token' => RecruitmentLink::generateToken(),
        'kind' => RecruitmentLinkKind::Branch,
        'branch_id' => $branch->id,
        'created_by_user_id' => $creator->id,
        'expires_at' => now()->addDays(30),
        ...$attributes,
    ]);
}

function callCentreLink(User $creator, array $attributes = []): RecruitmentLink
{
    return RecruitmentLink::create([
        'token' => RecruitmentLink::generateToken(),
        'kind' => RecruitmentLinkKind::CallCenter,
        'branch_id' => null,
        'created_by_user_id' => $creator->id,
        'expires_at' => now()->addDays(30),
        ...$attributes,
    ]);
}

function applicationPayload(array $overrides = []): array
{
    return [
        'name' => 'Ama Mensah',
        'phone' => '0541234567',
        'phone_confirmation' => '0541234567',
        'password' => 'chosen-by-me-1',
        'password_confirmation' => 'chosen-by-me-1',
        ...$overrides,
    ];
}

function apply(RecruitmentLink $link, User $creator, array $overrides = []): RecruitmentApplication
{
    return RecruitmentApplication::create([
        'recruitment_link_id' => $link->id,
        'name' => 'Ama Mensah',
        'phone' => '+233541234567',
        'password_hash' => Hash::make('chosen-by-me-1'),
        ...$overrides,
    ]);
}

beforeEach(function () {
    // Approving sends a welcome message. That is a real outbound call to Hubtel,
    // which no test should be making.
    Notification::fake();

    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->create(['name' => 'Lakeside']);
    ['user' => $this->admin] = recruitStaff(RoleEnum::Admin->value);
});

/*
|--------------------------------------------------------------------------
| The public form
|--------------------------------------------------------------------------
*/

describe('the form a recruit fills in', function () {
    it('says what the posting is before asking for anything', function () {
        $link = branchLink($this->branch, $this->admin, ['label' => 'November intake']);

        $this->getJson("/v1/recruit/{$link->token}")
            ->assertOk()
            ->assertJsonPath('data.kind', RecruitmentLinkKind::Branch->value)
            ->assertJsonPath('data.posting', 'Lakeside')
            ->assertJsonPath('data.label', 'November intake');
    });

    it('names the call centre as the posting when there is no branch', function () {
        $link = callCentreLink($this->admin);

        $this->getJson("/v1/recruit/{$link->token}")
            ->assertOk()
            ->assertJsonPath('data.posting', 'Call Centre')
            ->assertJsonPath('data.branch_name', null);
    });

    it('takes a submission without creating anything that can log in', function () {
        $link = branchLink($this->branch, $this->admin);

        $this->postJson("/v1/recruit/{$link->token}", applicationPayload())
            ->assertCreated();

        expect(RecruitmentApplication::count())->toBe(1)
            ->and(RecruitmentApplication::first()->status)->toBe(RecruitmentApplicationStatus::Pending)
            // The whole point of the review step.
            ->and(User::where('phone', '+233541234567')->exists())->toBeFalse()
            ->and(Employee::count())->toBe(1); // just the admin from beforeEach
    });

    it('stores the phone normalised, however it was typed', function () {
        $link = branchLink($this->branch, $this->admin);

        $this->postJson("/v1/recruit/{$link->token}", applicationPayload([
            'phone' => '024 111 2222',
            'phone_confirmation' => '+233241112222',
        ]))->assertCreated();

        expect(RecruitmentApplication::first()->phone)->toBe('+233241112222');
    });

    it('never stores the password in the clear', function () {
        $link = branchLink($this->branch, $this->admin);

        $this->postJson("/v1/recruit/{$link->token}", applicationPayload())->assertCreated();

        $stored = RecruitmentApplication::first()->getRawOriginal('password_hash');

        expect($stored)->not->toBe('chosen-by-me-1')
            ->and(Hash::check('chosen-by-me-1', $stored))->toBeTrue();
    });

    it('makes them type the phone number twice', function () {
        $link = branchLink($this->branch, $this->admin);

        $this->postJson("/v1/recruit/{$link->token}", applicationPayload([
            'phone_confirmation' => '0559999999',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    });

    it('makes them type the password twice', function () {
        $link = branchLink($this->branch, $this->admin);

        $this->postJson("/v1/recruit/{$link->token}", applicationPayload([
            'password_confirmation' => 'something-else',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    });

    it('gives the applicant no way to choose a role or a branch', function () {
        $otherBranch = Branch::factory()->create();
        $link = branchLink($this->branch, $this->admin);

        $this->postJson("/v1/recruit/{$link->token}", applicationPayload([
            'role' => RoleEnum::Admin->value,
            'branch_id' => $otherBranch->id,
            'branch_ids' => [$otherBranch->id],
            'status' => EmployeeStatus::Active->value,
        ]))->assertCreated();

        $application = RecruitmentApplication::first();

        // Neither was validated, so neither was kept — the branch still comes
        // from the link and the role is still the reviewer's to pick.
        expect($application->recruitment_link_id)->toBe($link->id)
            ->and($application->getAttributes())->not->toHaveKey('role')
            ->and($application->link->branch_id)->toBe($this->branch->id);
    });

    it('does not collect SSNIT or TIN', function () {
        $link = branchLink($this->branch, $this->admin);

        // An applicant is not on payroll and often has neither yet. Both stay on
        // `employees`, filled in by the staff editor after the hire.
        $this->postJson("/v1/recruit/{$link->token}", applicationPayload([
            'ssnit_number' => 'SSN-123',
            'tin_number' => 'TIN-456',
        ]))->assertCreated();

        $stored = RecruitmentApplication::first()->getAttributes();

        expect($stored)->not->toHaveKey('ssnit_number')
            ->and($stored)->not->toHaveKey('tin_number');
    });

    it('turns away someone who already has a staff account', function () {
        $link = branchLink($this->branch, $this->admin);
        ['user' => $existing] = recruitStaff(RoleEnum::SalesStaff->value, $this->branch);
        $existing->update(['phone' => '+233541234567']);

        $this->postJson("/v1/recruit/{$link->token}", applicationPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    });

    it('welcomes an existing customer, because that is the reuse path', function () {
        $link = branchLink($this->branch, $this->admin);
        User::factory()->create(['phone' => '+233541234567']); // no employee record

        $this->postJson("/v1/recruit/{$link->token}", applicationPayload())
            ->assertCreated();
    });

    it('refuses a second open application to the same posting', function () {
        $link = branchLink($this->branch, $this->admin);

        $this->postJson("/v1/recruit/{$link->token}", applicationPayload())->assertCreated();

        $this->postJson("/v1/recruit/{$link->token}", applicationPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        expect(RecruitmentApplication::count())->toBe(1);
    });

    it('lets someone rejected once apply to that posting again', function () {
        $link = branchLink($this->branch, $this->admin);
        apply($link, $this->admin, ['status' => RecruitmentApplicationStatus::Rejected]);

        // The unique index has to be partial for this. A plain unique on
        // (link, phone, status) forbids the second rejected row too, and the
        // failure surfaces as a 500 on the form rather than a rule anyone can see.
        $this->postJson("/v1/recruit/{$link->token}", applicationPayload())
            ->assertCreated();

        expect(RecruitmentApplication::count())->toBe(2);
    });
});

describe('a link that is no longer open', function () {
    it('turns away a reader once it has expired', function () {
        $link = branchLink($this->branch, $this->admin, ['expires_at' => now()->subDay()]);

        $this->getJson("/v1/recruit/{$link->token}")->assertNotFound();
    });

    it('turns away a submission once it has expired', function () {
        $link = branchLink($this->branch, $this->admin, ['expires_at' => now()->subDay()]);

        $this->postJson("/v1/recruit/{$link->token}", applicationPayload())
            ->assertNotFound();

        expect(RecruitmentApplication::count())->toBe(0);
    });

    it('answers a closed link the same way whether it expired or never existed', function () {
        $expired = branchLink($this->branch, $this->admin, ['expires_at' => now()->subDay()]);

        $real = $this->getJson("/v1/recruit/{$expired->token}");
        $imaginary = $this->getJson('/v1/recruit/'.str_repeat('x', 48));

        // Telling them apart would turn this into a way of testing whether a
        // token is real, which is the whole of the token's value.
        expect($real->status())->toBe($imaginary->status())
            ->and($real->json('message'))->toBe($imaginary->json('message'));
    });

    it('says the link is shut rather than complaining about the form', function () {
        $link = branchLink($this->branch, $this->admin, ['expires_at' => now()->subDay()]);

        // A closed link posted with a bad phone must answer about the link. The
        // check has to sit in authorize(), because a form request validates
        // before the controller ever runs.
        $this->postJson("/v1/recruit/{$link->token}", applicationPayload([
            'phone_confirmation' => 'nonsense',
        ]))->assertNotFound();
    });
});

/*
|--------------------------------------------------------------------------
| Opening a posting
|--------------------------------------------------------------------------
*/

describe('creating a link', function () {
    it('opens a branch posting', function () {
        $this->actingAs($this->admin)
            ->postJson('/v1/admin/recruitment-links', [
                'kind' => RecruitmentLinkKind::Branch->value,
                'branch_id' => $this->branch->id,
                'expires_at' => now()->addDays(30)->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.posting', 'Lakeside');

        expect(RecruitmentLink::first()->branch_id)->toBe($this->branch->id);
    });

    it('refuses a branch posting with no branch', function () {
        $this->actingAs($this->admin)
            ->postJson('/v1/admin/recruitment-links', [
                'kind' => RecruitmentLinkKind::Branch->value,
                'expires_at' => now()->addDays(30)->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
    });

    it('drops a branch sent for a call-centre posting instead of refusing it', function () {
        $this->actingAs($this->admin)
            ->postJson('/v1/admin/recruitment-links', [
                'kind' => RecruitmentLinkKind::CallCenter->value,
                'branch_id' => $this->branch->id,
                'expires_at' => now()->addDays(30)->toIso8601String(),
            ])
            ->assertCreated();

        expect(RecruitmentLink::first()->branch_id)->toBeNull();
    });

    it('insists on a closing date, because nothing else shuts a link', function () {
        $this->actingAs($this->admin)
            ->postJson('/v1/admin/recruitment-links', [
                'kind' => RecruitmentLinkKind::Branch->value,
                'branch_id' => $this->branch->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expires_at');
    });

    it('refuses a closing date in the past', function () {
        $this->actingAs($this->admin)
            ->postJson('/v1/admin/recruitment-links', [
                'kind' => RecruitmentLinkKind::Branch->value,
                'branch_id' => $this->branch->id,
                'expires_at' => now()->subDay()->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expires_at');
    });

    it('gives each link an unguessable token', function () {
        $first = branchLink($this->branch, $this->admin);
        $second = branchLink($this->branch, $this->admin);

        expect($first->token)->not->toBe($second->token)
            ->and(strlen($first->token))->toBeGreaterThanOrEqual(32);
    });

    it('tells the client which roles the posting may appoint', function () {
        $branchLink = branchLink($this->branch, $this->admin);
        $callCentre = callCentreLink($this->admin);

        $branchRoles = array_column($branchLink->kind->assignableRoles(), 'value');
        $callCentreRoles = array_column($callCentre->kind->assignableRoles(), 'value');

        expect($branchRoles)->toContain(RoleEnum::SalesStaff->value)
            ->and($branchRoles)->toContain(RoleEnum::Manager->value)
            ->and($branchRoles)->not->toContain(RoleEnum::CallCenter->value)
            ->and($branchRoles)->not->toContain(RoleEnum::Admin->value)
            ->and($branchRoles)->not->toContain(RoleEnum::TechAdmin->value)
            ->and($callCentreRoles)->toBe([RoleEnum::CallCenter->value]);
    });
});

describe('editing a posting', function () {
    it('renames it', function () {
        $link = branchLink($this->branch, $this->admin, ['label' => 'Old name']);

        $this->actingAs($this->admin)
            ->patchJson("/v1/admin/recruitment-links/{$link->id}", ['label' => 'November intake'])
            ->assertOk()
            ->assertJsonPath('data.label', 'November intake');
    });

    it('closes it by moving the date into the past', function () {
        $link = branchLink($this->branch, $this->admin);

        $this->actingAs($this->admin)
            ->patchJson("/v1/admin/recruitment-links/{$link->id}", [
                'expires_at' => now()->subMinute()->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.is_expired', true);

        // Which is the whole point: the form stops taking submissions.
        $this->postJson("/v1/recruit/{$link->token}", applicationPayload())->assertNotFound();
    });

    it('reopens one that was closed', function () {
        $link = branchLink($this->branch, $this->admin, ['expires_at' => now()->subDay()]);

        $this->actingAs($this->admin)
            ->patchJson("/v1/admin/recruitment-links/{$link->id}", [
                'expires_at' => now()->addDays(14)->toIso8601String(),
            ])
            ->assertOk();

        $this->postJson("/v1/recruit/{$link->token}", applicationPayload())->assertCreated();
    });

    it('will not move the branch out from under people who already applied', function () {
        $other = Branch::factory()->create(['name' => 'Ashaiman']);
        $link = branchLink($this->branch, $this->admin);
        apply($link, $this->admin);

        $this->actingAs($this->admin)
            ->patchJson("/v1/admin/recruitment-links/{$link->id}", [
                'branch_id' => $other->id,
                'kind' => RecruitmentLinkKind::CallCenter->value,
            ])
            ->assertOk();

        // Neither field is validated, so neither is written. Changing them would
        // send pending applicants to a branch they never applied to, and nothing
        // on screen would look wrong.
        expect($link->fresh()->branch_id)->toBe($this->branch->id)
            ->and($link->fresh()->kind)->toBe(RecruitmentLinkKind::Branch);
    });

    it('is closed to staff who cannot manage employees', function () {
        $link = branchLink($this->branch, $this->admin);
        ['user' => $cashier] = recruitStaff(RoleEnum::SalesStaff->value, $this->branch);

        $this->actingAs($cashier)
            ->patchJson("/v1/admin/recruitment-links/{$link->id}", ['label' => 'Mine now'])
            ->assertForbidden();
    });
});

describe('deleting a posting', function () {
    it('deletes one nobody has applied to', function () {
        $link = branchLink($this->branch, $this->admin);

        $this->actingAs($this->admin)
            ->deleteJson("/v1/admin/recruitment-links/{$link->id}")
            ->assertNoContent();

        expect(RecruitmentLink::count())->toBe(0);
    });

    it('refuses to delete one that has applications', function () {
        $link = branchLink($this->branch, $this->admin);
        apply($link, $this->admin);

        // The foreign key cascades, so this would take the applications with it
        // — including approved ones carrying created_user_id, the only record of
        // which form a staff account came from.
        $this->actingAs($this->admin)
            ->deleteJson("/v1/admin/recruitment-links/{$link->id}")
            ->assertUnprocessable();

        expect(RecruitmentLink::count())->toBe(1)
            ->and(RecruitmentApplication::count())->toBe(1);
    });

    it('still refuses when the only application was rejected', function () {
        $link = branchLink($this->branch, $this->admin);
        apply($link, $this->admin, ['status' => RecruitmentApplicationStatus::Rejected]);

        $this->actingAs($this->admin)
            ->deleteJson("/v1/admin/recruitment-links/{$link->id}")
            ->assertUnprocessable();
    });

    it('is closed to staff who cannot manage employees', function () {
        $link = branchLink($this->branch, $this->admin);
        ['user' => $cashier] = recruitStaff(RoleEnum::SalesStaff->value, $this->branch);

        $this->actingAs($cashier)
            ->deleteJson("/v1/admin/recruitment-links/{$link->id}")
            ->assertForbidden();

        expect(RecruitmentLink::count())->toBe(1);
    });
});

/*
|--------------------------------------------------------------------------
| Deciding
|--------------------------------------------------------------------------
*/

describe('approving an application', function () {
    it('creates the account, with the branch from the link', function () {
        $link = branchLink($this->branch, $this->admin);
        $application = apply($link, $this->admin);

        $this->actingAs($this->admin)
            ->postJson("/v1/admin/recruitment-applications/{$application->id}/approve", [
                'role' => RoleEnum::SalesStaff->value,
            ])
            ->assertOk();

        $user = User::where('phone', '+233541234567')->first();

        expect($user)->not->toBeNull()
            ->and($user->hasRole(RoleEnum::SalesStaff->value))->toBeTrue()
            ->and($user->employee)->not->toBeNull()
            ->and($user->employee->branches->pluck('id')->all())->toBe([$this->branch->id])
            ->and($application->fresh()->status)->toBe(RecruitmentApplicationStatus::Approved)
            ->and($application->fresh()->created_user_id)->toBe($user->id);
    });

    it('leaves a call-centre hire with no branch at all', function () {
        $link = callCentreLink($this->admin);
        $application = apply($link, $this->admin);

        $this->actingAs($this->admin)
            ->postJson("/v1/admin/recruitment-applications/{$application->id}/approve", [
                'role' => RoleEnum::CallCenter->value,
            ])
            ->assertOk();

        $user = User::where('phone', '+233541234567')->first();

        // Not a cosmetic detail. employee_branch cannot tell "not confined to a
        // branch" from "assigned no branches", so a call-centre agent carrying a
        // branch row sees an empty order list and nothing on screen looks wrong.
        expect($user->employee->branches)->toHaveCount(0)
            ->and($user->isCompanyWide())->toBeTrue();
    });

    it('lets the holder sign in with the password they chose', function () {
        $link = branchLink($this->branch, $this->admin);
        $application = apply($link, $this->admin);

        $this->actingAs($this->admin)
            ->postJson("/v1/admin/recruitment-applications/{$application->id}/approve", [
                'role' => RoleEnum::SalesStaff->value,
            ])
            ->assertOk();

        $user = User::where('phone', '+233541234567')->first();

        expect(Hash::check('chosen-by-me-1', $user->password))->toBeTrue()
            ->and($user->must_reset_password)->toBeFalse();
    });

    it('never puts a password the applicant chose into the readable vault', function () {
        $link = branchLink($this->branch, $this->admin);
        $application = apply($link, $this->admin);

        $this->actingAs($this->admin)
            ->postJson("/v1/admin/recruitment-applications/{$application->id}/approve", [
                'role' => RoleEnum::SalesStaff->value,
            ])
            ->assertOk();

        // The vault exists so an admin can pass on a password they generated. A
        // password the person picked themselves is not the admin's to read.
        expect(User::where('phone', '+233541234567')->first()->recoverable_password)->toBeNull();
    });

    it('sends a welcome that quotes no password', function () {
        $link = branchLink($this->branch, $this->admin);
        $application = apply($link, $this->admin);

        $this->actingAs($this->admin)
            ->postJson("/v1/admin/recruitment-applications/{$application->id}/approve", [
                'role' => RoleEnum::SalesStaff->value,
            ])
            ->assertOk();

        $user = User::where('phone', '+233541234567')->first();

        Notification::assertSentTo($user, StaffApplicationApprovedNotification::class,
            function (StaffApplicationApprovedNotification $notification) use ($user) {
                return ! str_contains($notification->toSms($user), 'chosen-by-me-1');
            });
    });

    it('greets the recruit by first name and tells them where to log in', function () {
        config(['app.frontend_url' => 'https://app.cedibites.com']);

        $link = branchLink($this->branch, $this->admin);
        $application = apply($link, $this->admin);

        $this->actingAs($this->admin)
            ->postJson("/v1/admin/recruitment-applications/{$application->id}/approve", [
                'role' => RoleEnum::SalesStaff->value,
            ])
            ->assertOk();

        $user = User::where('phone', '+233541234567')->first();

        Notification::assertSentTo($user, StaffApplicationApprovedNotification::class,
            function (StaffApplicationApprovedNotification $notification) use ($user) {
                $sms = $notification->toSms($user);

                // First name, not "Ama Mensah" — and a link they can tap rather
                // than a portal name they would have to go looking for.
                return str_contains($sms, 'Hi Ama,')
                    && ! str_contains($sms, 'Ama Mensah')
                    && str_contains($sms, 'https://app.cedibites.com/staff/login');
            });
    });

    it('refuses a role the posting cannot appoint', function () {
        $link = branchLink($this->branch, $this->admin);
        $application = apply($link, $this->admin);

        $this->actingAs($this->admin)
            ->postJson("/v1/admin/recruitment-applications/{$application->id}/approve", [
                'role' => RoleEnum::CallCenter->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        expect($application->fresh()->status)->toBe(RecruitmentApplicationStatus::Pending)
            ->and(User::where('phone', '+233541234567')->exists())->toBeFalse();
    });

    it('refuses a branch role on a call-centre posting', function () {
        $link = callCentreLink($this->admin);
        $application = apply($link, $this->admin);

        $this->actingAs($this->admin)
            ->postJson("/v1/admin/recruitment-applications/{$application->id}/approve", [
                'role' => RoleEnum::SalesStaff->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    });

    it('refuses tech_admin, as everywhere else', function () {
        $link = branchLink($this->branch, $this->admin);
        $application = apply($link, $this->admin);

        $this->actingAs($this->admin)
            ->postJson("/v1/admin/recruitment-applications/{$application->id}/approve", [
                'role' => RoleEnum::TechAdmin->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    });

    it('refuses admin, which is a head-office hire and not a posting', function () {
        $link = branchLink($this->branch, $this->admin);
        $application = apply($link, $this->admin);

        $this->actingAs($this->admin)
            ->postJson("/v1/admin/recruitment-applications/{$application->id}/approve", [
                'role' => RoleEnum::Admin->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    });

    it('cannot be approved twice', function () {
        $link = branchLink($this->branch, $this->admin);
        $application = apply($link, $this->admin);

        $this->actingAs($this->admin)
            ->postJson("/v1/admin/recruitment-applications/{$application->id}/approve", [
                'role' => RoleEnum::SalesStaff->value,
            ])
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/v1/admin/recruitment-applications/{$application->id}/approve", [
                'role' => RoleEnum::SalesStaff->value,
            ])
            ->assertUnprocessable();

        expect(Employee::whereHas('user', fn ($q) => $q->where('phone', '+233541234567'))->count())->toBe(1);
    });

    it('refuses when the applicant has been hired by hand since applying', function () {
        $link = branchLink($this->branch, $this->admin);
        $application = apply($link, $this->admin);

        // Time passes between submit and review.
        ['user' => $hired] = recruitStaff(RoleEnum::Kitchen->value, $this->branch);
        $hired->update(['phone' => '+233541234567']);

        $this->actingAs($this->admin)
            ->postJson("/v1/admin/recruitment-applications/{$application->id}/approve", [
                'role' => RoleEnum::SalesStaff->value,
            ])
            ->assertUnprocessable();

        expect($application->fresh()->status)->toBe(RecruitmentApplicationStatus::Pending)
            ->and($hired->fresh()->hasRole(RoleEnum::Kitchen->value))->toBeTrue();
    });

    it('brings an existing customer onto staff rather than duplicating them', function () {
        $link = branchLink($this->branch, $this->admin);
        $customer = User::factory()->create(['phone' => '+233541234567', 'name' => 'Ama']);
        $application = apply($link, $this->admin);

        $this->actingAs($this->admin)
            ->postJson("/v1/admin/recruitment-applications/{$application->id}/approve", [
                'role' => RoleEnum::SalesStaff->value,
            ])
            ->assertOk();

        expect(User::where('phone', '+233541234567')->count())->toBe(1)
            ->and($customer->fresh()->employee)->not->toBeNull();
    });
});

describe('rejecting an application', function () {
    it('marks it rejected and tells the applicant nothing', function () {
        $link = branchLink($this->branch, $this->admin);
        $application = apply($link, $this->admin);

        $this->actingAs($this->admin)
            ->postJson("/v1/admin/recruitment-applications/{$application->id}/reject")
            ->assertOk();

        expect($application->fresh()->status)->toBe(RecruitmentApplicationStatus::Rejected)
            ->and(User::where('phone', '+233541234567')->exists())->toBeFalse();

        Notification::assertNothingSent();
    });

    it('keeps their details, so a second application is recognised', function () {
        $link = branchLink($this->branch, $this->admin);
        $application = apply($link, $this->admin);

        $this->actingAs($this->admin)
            ->postJson("/v1/admin/recruitment-applications/{$application->id}/reject")
            ->assertOk();

        expect(RecruitmentApplication::find($application->id))->not->toBeNull();
    });

    it('cannot be rejected after being approved', function () {
        $link = branchLink($this->branch, $this->admin);
        $application = apply($link, $this->admin, [
            'status' => RecruitmentApplicationStatus::Approved,
        ]);

        $this->actingAs($this->admin)
            ->postJson("/v1/admin/recruitment-applications/{$application->id}/reject")
            ->assertUnprocessable();
    });
});

/*
|--------------------------------------------------------------------------
| Who may recruit for what
|--------------------------------------------------------------------------
*/

describe('a manager does not recruit', function () {
    /*
    | Approving an application *is* hiring — the account exists from that moment.
    | So recruitment sits behind `manage_employees`, the same gate as the staff
    | editor, and the manager deliberately does not hold it: "no hiring, no role
    | changes, no suspending access" (RoleSeeder). Letting him approve would
    | reopen the ceiling by another door, and a branch posting can appoint a
    | manager, so he could promote his own replacement — or himself, next time.
    */
    beforeEach(function () {
        $this->otherBranch = Branch::factory()->create(['name' => 'Ashaiman']);
        ['user' => $this->manager] = recruitStaff(RoleEnum::Manager->value, $this->branch);
    });

    it('cannot open a posting, even for the branch they run', function () {
        $this->actingAs($this->manager)
            ->postJson('/v1/admin/recruitment-links', [
                'kind' => RecruitmentLinkKind::Branch->value,
                'branch_id' => $this->branch->id,
                'expires_at' => now()->addDays(30)->toIso8601String(),
            ])
            ->assertForbidden();

        expect(RecruitmentLink::count())->toBe(0);
    });

    it('cannot read the review queue', function () {
        apply(branchLink($this->branch, $this->admin), $this->admin);

        // The queue holds Ghana Card numbers, dates of birth and next of kin for
        // people who do not work here yet.
        $this->actingAs($this->manager)
            ->getJson('/v1/admin/recruitment-applications')
            ->assertForbidden();
    });

    it('cannot approve anybody, not even at their own branch', function () {
        $application = apply(branchLink($this->branch, $this->admin), $this->admin);

        $this->actingAs($this->manager)
            ->postJson("/v1/admin/recruitment-applications/{$application->id}/approve", [
                'role' => RoleEnum::SalesStaff->value,
            ])
            ->assertForbidden();

        expect($application->fresh()->status)->toBe(RecruitmentApplicationStatus::Pending)
            ->and(User::where('phone', '+233541234567')->exists())->toBeFalse();
    });
});

describe('the branch scoping under the gate', function () {
    /*
    | Nobody branch-scoped holds `manage_employees` today, so these exercise the
    | scoping directly rather than through a role. They are not idle: the moment
    | anyone grants that permission to a branch role, the difference between
    | "sees their own branch" and "sees every applicant in the company" is these
    | few lines — and getting it wrong leaks HR records, quietly.
    */
    beforeEach(function () {
        $this->otherBranch = Branch::factory()->create(['name' => 'Ashaiman']);
        ['user' => $this->scoped] = recruitStaff(RoleEnum::Manager->value, $this->branch);
        $this->scoped->givePermissionTo('manage_employees');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    });

    it('shows only the branch they are assigned to', function () {
        apply(branchLink($this->branch, $this->admin), $this->admin);
        apply(branchLink($this->otherBranch, $this->admin), $this->admin, ['phone' => '+233559998888']);

        $response = $this->actingAs($this->scoped)
            ->getJson('/v1/admin/recruitment-applications')
            ->assertOk();

        expect($response->json('data.data'))->toHaveCount(1)
            ->and($response->json('data.data.0.phone'))->toBe('+233541234567');
    });

    it('hides call-centre applications, which belong to no branch', function () {
        apply(callCentreLink($this->admin), $this->admin);

        $response = $this->actingAs($this->scoped)
            ->getJson('/v1/admin/recruitment-applications')
            ->assertOk();

        expect($response->json('data.data'))->toHaveCount(0);
    });

    it('answers 404 for an application at another branch', function () {
        $application = apply(branchLink($this->otherBranch, $this->admin), $this->admin);

        // 404 rather than 403 — an application they cannot see does not exist to
        // them, and a 403 would confirm it is there.
        $this->actingAs($this->scoped)
            ->postJson("/v1/admin/recruitment-applications/{$application->id}/approve", [
                'role' => RoleEnum::SalesStaff->value,
            ])
            ->assertNotFound();

        expect($application->fresh()->status)->toBe(RecruitmentApplicationStatus::Pending);
    });

    it('refuses a posting for a branch they are not assigned to', function () {
        $this->actingAs($this->scoped)
            ->postJson('/v1/admin/recruitment-links', [
                'kind' => RecruitmentLinkKind::Branch->value,
                'branch_id' => $this->otherBranch->id,
                'expires_at' => now()->addDays(30)->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
    });

    it('refuses to let them recruit for the call centre', function () {
        $this->actingAs($this->scoped)
            ->postJson('/v1/admin/recruitment-links', [
                'kind' => RecruitmentLinkKind::CallCenter->value,
                'expires_at' => now()->addDays(30)->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('kind');
    });
});

describe('an admin belongs to no branch and still sees everything', function () {
    it('lists every posting\'s applications', function () {
        $other = Branch::factory()->create();
        apply(branchLink($this->branch, $this->admin), $this->admin);
        apply(branchLink($other, $this->admin), $this->admin, ['phone' => '+233559998888']);
        apply(callCentreLink($this->admin), $this->admin, ['phone' => '+233557776666']);

        // isCompanyWide() first, always: an admin has no branch rows, and read
        // as "assigned no branches" they would see nothing at all.
        $response = $this->actingAs($this->admin)
            ->getJson('/v1/admin/recruitment-applications')
            ->assertOk();

        expect($response->json('data.data'))->toHaveCount(3);
    });
});

describe('the review queue', function () {
    it('shows what needs a decision by default', function () {
        $link = branchLink($this->branch, $this->admin);
        apply($link, $this->admin);
        apply($link, $this->admin, [
            'phone' => '+233559998888',
            'status' => RecruitmentApplicationStatus::Rejected,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/v1/admin/recruitment-applications')
            ->assertOk();

        expect($response->json('data.data'))->toHaveCount(1)
            ->and($response->json('data.data.0.status'))->toBe(RecruitmentApplicationStatus::Pending->value);
    });

    it('shows history when asked for it', function () {
        $link = branchLink($this->branch, $this->admin);
        apply($link, $this->admin);
        apply($link, $this->admin, [
            'phone' => '+233559998888',
            'status' => RecruitmentApplicationStatus::Rejected,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/v1/admin/recruitment-applications?status=all')
            ->assertOk();

        expect($response->json('data.data'))->toHaveCount(2);
    });

    it('is closed to staff who cannot manage employees', function () {
        ['user' => $cashier] = recruitStaff(RoleEnum::SalesStaff->value, $this->branch);

        $this->actingAs($cashier)
            ->getJson('/v1/admin/recruitment-applications')
            ->assertForbidden();
    });

    it('is closed to the public entirely', function () {
        $this->getJson('/v1/admin/recruitment-applications')->assertUnauthorized();
    });
});
