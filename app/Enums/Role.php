<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

enum Role: string
{
    use HasEnumHelpers;

    case TechAdmin = 'tech_admin';
    case Admin = 'admin';
    case Manager = 'manager';
    case SalesStaff = 'sales_staff';
    case BranchPartner = 'branch_partner';
    case CallCenter = 'call_center';
    case Kitchen = 'kitchen';
    case Rider = 'rider';
    case WarehouseManager = 'warehouse_manager';
    case PurchasingClerk = 'purchasing_clerk';

    /** Friendly, human-readable role name (used in emails & notifications). */
    public function label(): string
    {
        return match ($this) {
            self::TechAdmin => 'Platform Admin',
            self::Admin => 'Administrator',
            self::Manager => 'Branch Manager',
            self::SalesStaff => 'Sales Staff',
            self::BranchPartner => 'Branch Partner',
            self::CallCenter => 'Call Center Agent',
            self::Kitchen => 'Kitchen Staff',
            self::Rider => 'Rider',
            self::WarehouseManager => 'Warehouse Manager',
            self::PurchasingClerk => 'Purchasing Clerk',
        };
    }

    /** Which portal this role lands in after logging in (single /staff/login entry). */
    public function portalLabel(): string
    {
        return match ($this) {
            self::TechAdmin, self::Admin => 'Admin Portal',
            self::BranchPartner => 'Partner Portal',
            default => 'Staff Portal',
        };
    }

    /**
     * How many branches this role is assigned.
     *
     * Head office, the warehouse and the call centre serve the whole company and
     * take no branch. A manager runs one branch — that is what the job is, and
     * letting one account run three was how a single login came to cover the
     * business. Riders and partners legitimately span several.
     */
    public function branchRule(): BranchRule
    {
        return match ($this) {
            self::TechAdmin,
            self::Admin,
            self::WarehouseManager,
            self::PurchasingClerk,
            self::CallCenter => BranchRule::None,

            self::Manager,
            self::SalesStaff,
            self::Kitchen => BranchRule::ExactlyOne,

            self::Rider,
            self::BranchPartner => BranchRule::OneOrMore,
        };
    }

    /**
     * Whether this role can be handed out through the ordinary staff editor.
     *
     * Only `tech_admin` cannot. It is the one role that carries the platform
     * tools — the password vault, maintenance mode, the ability to mint more
     * tech admins — and the whole point of separating it from `admin` is that
     * the business owner cannot reach those. That separation is worth nothing
     * if the staff editor will grant it, so the role is issued exclusively
     * through the passcode-gated platform portal, by someone who already holds
     * it. See PlatformController::createAdmin.
     */
    public function isAssignableByAdmin(): bool
    {
        return $this !== self::TechAdmin;
    }

    /**
     * The roles the staff editor may offer.
     *
     * @return array<int, string>
     */
    public static function assignableByAdmin(): array
    {
        return array_values(array_map(
            fn (self $role) => $role->value,
            array_filter(self::cases(), fn (self $role) => $role->isAssignableByAdmin()),
        ));
    }

    /**
     * Ranking used when an account is found holding more than one role and one
     * has to survive. Higher wins. Only `iam:normalize-staff` reads this — it is
     * a repair ordering, not a permission hierarchy.
     */
    public function privilegeRank(): int
    {
        return match ($this) {
            self::TechAdmin => 100,
            self::Admin => 90,
            self::Manager => 70,
            self::WarehouseManager => 60,
            self::PurchasingClerk => 50,
            self::BranchPartner => 40,
            self::CallCenter => 30,
            self::SalesStaff => 20,
            self::Kitchen => 10,
            self::Rider => 10,
        };
    }
}
