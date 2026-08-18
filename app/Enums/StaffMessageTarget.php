<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

/**
 * Who a rule messages when it fires. Composable — a stalled order can reasonably
 * go to the person who left it and to the manager of the branch at the same time,
 * and those are the two halves of accountability rather than alternatives.
 */
enum StaffMessageTarget: string
{
    use HasEnumHelpers;

    /**
     * The staff member responsible for the thing that went wrong — whoever last
     * moved the order, or created it. Resolved per event, because "responsible"
     * means something different for a stall than for a junk phone number.
     */
    case Actor = 'actor';

    /** Managers of the branch the subject belongs to. */
    case BranchManagers = 'branch_managers';

    /** Everyone assigned to that branch. Use sparingly — it is a pile-on by design. */
    case BranchStaff = 'branch_staff';

    /** Named roles, company-wide. */
    case Roles = 'roles';

    /** admin + tech_admin. The escalation target. */
    case Admins = 'admins';

    public function label(): string
    {
        return match ($this) {
            self::Actor => 'The staff member responsible',
            self::BranchManagers => 'Managers of that branch',
            self::BranchStaff => 'All staff at that branch',
            self::Roles => 'Specific roles',
            self::Admins => 'Admins and IT',
        };
    }
}
