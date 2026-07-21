<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * One-shot production data fix.
 *
 * The Warehouse Manager role was originally seeded (additively) with several
 * Admin-only inventory permissions, leaving it indistinguishable from Admin
 * inside the inventory portal. This revokes those grants so the role matches the
 * architecture §3 permission matrix (docs/agents/inventory-auditor-kb.md):
 * operations + items/suppliers upkeep, but NOT recipe authoring/locking,
 * structural master-data setup, or IMS role assignment.
 *
 * RoleSeeder is deliberately additive (givePermissionTo, never sync), so on an
 * environment that was already seeded this cleanup is required to actually remove
 * the grants. Idempotent — revokePermissionTo is a no-op for permissions the role
 * no longer holds, so it is safe to re-run.
 *
 * Run once per already-seeded environment:
 *   php artisan db:seed --class=WarehouseManagerCleanupSeeder --force
 */
class WarehouseManagerCleanupSeeder extends Seeder
{
    public function run(): void
    {
        $warehouseManager = Role::where('name', RoleEnum::WarehouseManager->value)
            ->where('guard_name', 'api')
            ->first();

        if (! $warehouseManager) {
            return;
        }

        $warehouseManager->revokePermissionTo([
            // Recipe authoring/locking is Admin-only ("I'll do the BOMs myself").
            Permission::InventoryRecipeEditGlobal->value,
            Permission::InventoryRecipeOverridePerBranch->value,
            Permission::InventoryRecipeLock->value,
            // Gates structural master-data setup (units/categories/locations) and
            // IMS role assignment — both Admin-only.
            Permission::InventorySettingsManage->value,
        ]);
    }
}
