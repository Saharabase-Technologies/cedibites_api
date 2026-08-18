<?php

namespace App\Services\StaffMessaging;

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Turns a chosen audience into the people who will actually receive a message.
 *
 * Deliberately NOT built on the campaign work's `AudienceResolver`. That one
 * profiles customers out of order history — who bought what, where, how often —
 * and shares nothing with this but the word "audience". A staff audience is
 * roles crossed with branches, answerable in one query, and forcing the two
 * through a common abstraction would make both harder to read.
 *
 * Shape of the audience array:
 *
 *   [
 *     'everyone'             => bool,
 *     'roles'                => ['rider', 'sales_staff'],
 *     'branch_ids'           => [1, 2],
 *     'user_ids'             => [17],
 *     'include_company_wide' => bool,
 *   ]
 */
class StaffAudienceResolver
{
    /**
     * @param  array<string, mixed>  $audience
     * @return Collection<int, User>
     */
    public function resolve(array $audience): Collection
    {
        $roles = array_values(array_filter((array) data_get($audience, 'roles', [])));
        $branchIds = array_values(array_filter((array) data_get($audience, 'branch_ids', [])));
        $userIds = array_values(array_filter((array) data_get($audience, 'user_ids', [])));
        $everyone = (bool) data_get($audience, 'everyone', false);
        $includeCompanyWide = (bool) data_get($audience, 'include_company_wide', true);

        // Named individuals come in regardless of the role and branch filters.
        // Picking somebody by name is an override of those filters, not a further
        // condition on them — otherwise "these two riders, plus Ama from head
        // office" is unexpressible.
        $named = $userIds === []
            ? collect()
            : $this->baseQuery()->whereIn('users.id', $userIds)->get();

        if (! $everyone && $roles === [] && $branchIds === []) {
            return $named->unique('id')->values();
        }

        $query = $this->baseQuery();

        if (! $everyone) {
            if ($roles !== []) {
                $query->whereHas('roles', fn (Builder $q) => $q->whereIn('name', $roles));
            }

            if ($branchIds !== []) {
                $query->where(function (Builder $q) use ($branchIds, $includeCompanyWide) {
                    $q->whereHas(
                        'employee.branches',
                        fn (Builder $b) => $b->whereIn('branches.id', $branchIds)
                    );

                    // The trap this whole block exists for. Head office, the
                    // warehouse, purchasing and the call centre hold NO branch
                    // assignment — see Role::branchRule(). A plain pivot filter
                    // reads that as "belongs to no branch" and excludes them from
                    // every branch, when the truth is the opposite: they serve
                    // all of them. The same misreading once hid every order from
                    // the call centre.
                    if ($includeCompanyWide) {
                        $q->orWhereHas(
                            'roles',
                            fn (Builder $r) => $r->whereIn('name', $this->companyWideRoleNames())
                        );
                    }
                });
            }
        }

        return $query->get()->concat($named)->unique('id')->values();
    }

    /**
     * How many people this audience currently reaches, without loading them.
     *
     * "Currently" is the operative word — the figure moves as staff join, leave
     * and change branch, which is why the audience is stored alongside the
     * resolved recipient list rather than instead of it.
     */
    public function count(array $audience): int
    {
        return $this->resolve($audience)->count();
    }

    /**
     * Everyone who may receive anything at all.
     *
     * Employment must be Active — the same bar EnsureStaffActive applies to the
     * staff surface itself. Messaging somebody who cannot log in to read it is
     * pure noise in the delivery figures, and on a suspension it is worse than
     * noise.
     */
    private function baseQuery(): Builder
    {
        return User::query()
            ->whereHas('employee', fn (Builder $q) => $q->where('status', EmployeeStatus::Active->value))
            ->with(['employee.branches', 'roles']);
    }

    /**
     * @return array<int, string>
     */
    private function companyWideRoleNames(): array
    {
        return collect(RoleEnum::cases())
            ->filter(fn (RoleEnum $role) => $role->branchRule() === \App\Enums\BranchRule::None)
            ->map(fn (RoleEnum $role) => $role->value)
            ->values()
            ->all();
    }

    /**
     * Admins and IT — the people a staff query goes to, and the escalation target
     * for rules.
     *
     * @return Collection<int, User>
     */
    public function itTeam(): Collection
    {
        return $this->baseQuery()
            ->whereHas('roles', fn (Builder $q) => $q->whereIn('name', [
                RoleEnum::TechAdmin->value,
                RoleEnum::Admin->value,
            ]))
            ->get();
    }
}
