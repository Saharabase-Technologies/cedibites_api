<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;

/**
 * Nobody below Admin sees the figures of a branch they are not assigned to.
 *
 * Shared by every controller that returns money by branch. Analytics held
 * this rule first. Finance reads the same orders, so the rule lives here,
 * once, and both use it. Two copies is how two screens come to disagree
 * about who may see what.
 *
 * `User::isCompanyWide()` was asked, and it is not the test here. It is true
 * for the warehouse, purchasing and the call centre as well as for admin,
 * because none of them takes a branch. That is right for orders and wrong
 * for money. For figures, only admin and tech_admin sit above the branches.
 */
trait ScopesToAssignedBranches
{
    /**
     * Build the standard filter set from the request.
     *
     * Supports a single `branch_id` or a `branch_ids[]` array (used by the
     * partner portal to aggregate across a partner's assigned branches).
     */
    protected function filters(Request $request): array
    {
        $filters = $request->only(['date_from', 'date_to', 'branch_id']);

        $branchIds = $request->input('branch_ids');
        if (is_array($branchIds) && count($branchIds) > 0) {
            $filters['branch_ids'] = array_values(array_map('intval', $branchIds));
        }

        $this->restrictBranchScope($request, $filters);

        return $filters;
    }

    /**
     * Hold the requested branches to the ones the caller is assigned to.
     *
     * Intersects any requested branch scope with the caller's own branches
     * (defaulting to all of them when none is specified), so a crafted
     * `branch_id` cannot leak another branch's figures. The scope is decided
     * here, on the server, because `branch_id` is otherwise whatever the client
     * chose to send. The manager portal fills it from `staffUser.branches[0]`
     * and the admin portal from a `?branch=` query param. Dropping the
     * parameter altogether used to return company-wide totals to anyone holding
     * `view_orders`, which is every staff role.
     *
     * Only admin and tech_admin sit above the branch structure. Everyone else
     * (manager, branch partner, and any future role) is confined to their
     * assignment, and a user with no branches at all sees nothing.
     */
    protected function restrictBranchScope(Request $request, array &$filters): void
    {
        $assigned = $this->assignedBranchIds($request);

        if ($assigned === null) {
            return; // admin: no confinement
        }

        if (empty($assigned)) {
            $filters['branch_ids'] = [-1]; // assigned to nothing, so sees nothing
            unset($filters['branch_id']);

            return;
        }

        $requested = $filters['branch_ids']
            ?? (isset($filters['branch_id']) ? [(int) $filters['branch_id']] : []);

        $allowed = empty($requested)
            ? $assigned
            : array_values(array_intersect($requested, $assigned));

        $filters['branch_ids'] = empty($allowed) ? [-1] : $allowed;
        unset($filters['branch_id']);
    }

    /**
     * The branches this caller may see figures for.
     *
     * `null` means "every branch" and is reserved for admin and tech_admin. An
     * empty array means the caller is confined to nothing: a staff member with
     * no branch assignment. Callers must render that as no data rather than as
     * no restriction.
     *
     * @return list<int>|null
     */
    protected function assignedBranchIds(Request $request): ?array
    {
        $user = $request->user();

        if ($user?->hasAnyRole(['admin', 'tech_admin'])) {
            return null;
        }

        return $user?->employee
            ? $user->employee->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all()
            : [];
    }
}
