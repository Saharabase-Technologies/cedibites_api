<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

/**
 * What a recruitment link is hiring for.
 *
 * The kind decides the branch stamp and, through it, the roles the approval
 * screen may offer. It exists because `call_center` is `BranchRule::None` and a
 * call-centre agent carrying a branch row is worse than broken —
 * `employee_branch` cannot tell "not confined to a branch" from "assigned no
 * branches", so they log in to an empty order list and nothing on screen looks
 * wrong. Separating the link kinds is what guarantees the branch is genuinely
 * absent rather than merely ignored.
 */
enum RecruitmentLinkKind: string
{
    use HasEnumHelpers;

    /** Hiring into one branch. Stamps that branch on whoever is approved. */
    case Branch = 'branch';

    /** Hiring into the call centre, which serves every branch and belongs to none. */
    case CallCenter = 'call_center';

    public function label(): string
    {
        return match ($this) {
            self::Branch => 'Branch',
            self::CallCenter => 'Call Centre',
        };
    }

    /** Whether a link of this kind carries a branch_id. */
    public function requiresBranch(): bool
    {
        return $this === self::Branch;
    }

    /**
     * The roles this kind of link may produce, in the order a picker should show
     * them. Derived from Role::branchRule() rather than listed by hand, so a new
     * role lands on the correct side of the split without anyone remembering to
     * come back here. tech_admin is excluded by isAssignableByAdmin().
     *
     * @return array<int, Role>
     */
    public function assignableRoles(): array
    {
        return array_values(array_filter(
            Role::cases(),
            fn (Role $role) => $role->isAssignableByAdmin() && $this->allows($role),
        ));
    }

    /** Whether a link of this kind may produce this role. */
    public function allows(Role $role): bool
    {
        if (! $role->isAssignableByAdmin()) {
            return false;
        }

        return match ($this) {
            // Every role that takes a branch at all. A rider or partner is
            // OneOrMore and starts with the link's single branch; more are added
            // later in the staff editor.
            self::Branch => $role->branchRule()->requiresBranch(),

            // Deliberately the one role, not "every company-wide role". Admin,
            // warehouse and purchasing are head-office hires made by hand; this
            // link is sent to people applying to answer phones.
            self::CallCenter => $role === Role::CallCenter,
        };
    }
}
