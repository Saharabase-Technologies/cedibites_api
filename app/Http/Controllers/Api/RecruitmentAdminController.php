<?php

namespace App\Http\Controllers\Api;

use App\Domain\Staff\EmployeeProvisioningService;
use App\Domain\Staff\PasswordPlan;
use App\Enums\RecruitmentApplicationStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateRecruitmentLinkRequest;
use App\Http\Resources\RecruitmentApplicationResource;
use App\Http\Resources\RecruitmentLinkResource;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentLink;
use App\Models\User;
use App\Notifications\StaffApplicationApprovedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The reviewer's end of recruitment: open a posting, read what came in, decide.
 *
 * Everything here is branch-scoped, which means the standing hazard applies —
 * `employee_branch` cannot tell "not confined to a branch" from "assigned no
 * branches", so every query asks `isCompanyWide()` before it filters.
 */
class RecruitmentAdminController extends Controller
{
    public function links(Request $request): JsonResponse
    {
        $links = $this->scopeToViewer(
            RecruitmentLink::with(['branch', 'createdBy'])
                ->withCount(['applications', 'pendingApplications']),
            $request->user(),
        )
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return response()->success(
            RecruitmentLinkResource::collection($links)->response()->getData(true),
        );
    }

    public function createLink(CreateRecruitmentLinkRequest $request): JsonResponse
    {
        $link = RecruitmentLink::create([
            'token' => RecruitmentLink::generateToken(),
            'kind' => $request->input('kind'),
            'branch_id' => $request->input('branch_id'),
            'created_by_user_id' => $request->user()->id,
            'label' => $request->input('label'),
            'expires_at' => $request->date('expires_at'),
        ]);

        return response()->created(
            (new RecruitmentLinkResource($link->load('branch', 'createdBy')))->resolve(),
        );
    }

    public function applications(Request $request): JsonResponse
    {
        $query = RecruitmentApplication::with(['link.branch', 'reviewedBy'])
            ->whereHas('link', fn (Builder $q) => $this->scopeToViewer($q, $request->user()));

        // Default to the queue that needs a decision. Everything else is history.
        $status = $request->input('status', RecruitmentApplicationStatus::Pending->value);
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($request->filled('recruitment_link_id')) {
            $query->where('recruitment_link_id', $request->integer('recruitment_link_id'));
        }

        if ($request->filled('branch_id')) {
            $branchId = $request->integer('branch_id');
            $query->whereHas('link', fn (Builder $q) => $q->where('branch_id', $branchId));
        }

        $applications = $query->latest()->paginate($request->integer('per_page', 25));

        return response()->success(
            RecruitmentApplicationResource::collection($applications)->response()->getData(true),
        );
    }

    public function showApplication(Request $request, RecruitmentApplication $application): JsonResponse
    {
        $this->assertVisible($request->user(), $application);

        return response()->success(
            (new RecruitmentApplicationResource($application->load(['link.branch', 'reviewedBy'])))->resolve(),
        );
    }

    /**
     * Approve, and only now create the account.
     *
     * The payload carries a role and nothing else. The branch comes from the
     * link, so there is no branch field to tamper with and no way to land
     * someone at a branch they did not apply to.
     */
    public function approve(
        Request $request,
        RecruitmentApplication $application,
        EmployeeProvisioningService $provisioning,
    ): JsonResponse {
        $this->assertVisible($request->user(), $application);

        $link = $application->link;

        $validated = $request->validate([
            'role' => [
                'required',
                Rule::enum(Role::class),
                // tech_admin is never issued here — nor anywhere but the
                // passcode-gated platform portal.
                Rule::in(Role::assignableByAdmin()),
                function (string $attribute, mixed $value, \Closure $fail) use ($link) {
                    $role = Role::tryFrom((string) $value);

                    if (! $role || ! $link->kind->allows($role)) {
                        $fail("A {$link->kind->label()} posting cannot appoint that role.");
                    }
                },
            ],
        ]);

        $role = Role::from($validated['role']);

        // Time passes between submit and review. The applicant may have been
        // hired by hand in the meantime, or applied to a second posting that was
        // approved first — either way the phone now belongs to a staff account
        // and approving again would collide inside provisioning.
        $existing = User::where('phone', $application->phone)->first();
        if ($existing?->employee) {
            return response()->unprocessable(
                'This phone number already belongs to a staff account. The applicant has been hired since applying.'
            );
        }

        // Claim the row before doing any work. A conditional UPDATE is already
        // atomic, and the count it returns is the claim: whichever of two
        // reviewers gets there second changes nothing and is told so, rather
        // than both passing a read-then-write check and hiring the person twice.
        $claimed = RecruitmentApplication::whereKey($application->id)
            ->where('status', RecruitmentApplicationStatus::Pending)
            ->update([
                'status' => RecruitmentApplicationStatus::Approved,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

        if (! $claimed) {
            return response()->unprocessable('This application has already been decided.');
        }

        try {
            $result = $provisioning->provision(
                name: $application->name,
                phone: $application->phone,
                email: $application->email,
                role: $role,
                // Empty for the call centre, and that emptiness is the point — it
                // is what User::isCompanyWide() reads. Never substitute a branch.
                branchIds: $link->branchIdsForApproval(),
                // The applicant chose it and we hold only the hash. Nothing is
                // vaulted, nothing is echoed back, no forced reset.
                password: PasswordPlan::alreadyChosen($application->getRawOriginal('password_hash')),
                details: $application->employeeDetails(),
                notification: fn (User $user, ?string $plain) => new StaffApplicationApprovedNotification(
                    $role->value,
                    $role->branchRule()->requiresBranch() ? $link->branch?->name : null,
                ),
            );
        } catch (\Throwable $e) {
            // The claim is outside the provisioning transaction, so it does not
            // roll back with it. Put the application back in the queue —
            // otherwise a failed hire leaves a row marked approved that no
            // account exists for and no reviewer can act on again.
            RecruitmentApplication::whereKey($application->id)->update([
                'status' => RecruitmentApplicationStatus::Pending,
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
            ]);

            return response()->error('Failed to create the account: '.$e->getMessage(), 500);
        }

        RecruitmentApplication::whereKey($application->id)
            ->update(['created_user_id' => $result->employee->user_id]);

        return response()->success([
            'application' => (new RecruitmentApplicationResource($application->fresh(['link.branch', 'reviewedBy'])))->resolve(),
            'employee_id' => $result->employee->id,
        ], 'Application approved and account created.');
    }

    /**
     * Reject, silently.
     *
     * By decision the applicant is told nothing — no SMS, no email. Their details
     * stay on record so the same person applying again is recognised rather than
     * duplicated.
     */
    public function reject(Request $request, RecruitmentApplication $application): JsonResponse
    {
        $this->assertVisible($request->user(), $application);

        $claimed = RecruitmentApplication::whereKey($application->id)
            ->where('status', RecruitmentApplicationStatus::Pending)
            ->update([
                'status' => RecruitmentApplicationStatus::Rejected,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

        if (! $claimed) {
            return response()->unprocessable('This application has already been decided.');
        }

        return response()->success(
            (new RecruitmentApplicationResource($application->fresh(['link.branch', 'reviewedBy'])))->resolve(),
            'Application rejected.'
        );
    }

    /**
     * Confine a link query to what this user may see.
     *
     * `isCompanyWide()` first, always: an admin belongs to no branch, and read
     * as "assigned no branches" they would see nothing at all.
     */
    private function scopeToViewer(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isCompanyWide() || $user->hasAnyRole(['admin', 'tech_admin'])) {
            return $query;
        }

        $ownBranches = $user->employee
            ? $user->employee->branches()->pluck('branches.id')->all()
            : [];

        // A branch manager sees their own branch's postings. Call-centre
        // postings carry no branch and are head office's business.
        return $query->whereIn('branch_id', $ownBranches ?: [-1]);
    }

    /** 404 rather than 403 — an application they cannot see does not exist to them. */
    private function assertVisible(?User $user, RecruitmentApplication $application): void
    {
        $visible = $this->scopeToViewer(
            RecruitmentLink::whereKey($application->recruitment_link_id),
            $user,
        )->exists();

        abort_unless($visible, 404);
    }
}
