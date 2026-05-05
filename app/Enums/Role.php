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
}
