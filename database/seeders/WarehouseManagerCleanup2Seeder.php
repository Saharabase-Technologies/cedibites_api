<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Warehouse Manager Cleanup 2 — one-shot production data fix.
 *
 * Re-scopes the Warehouse Manager and Purchasing Clerk roles to match the revised
 * ownership model (RoleSeeder is authoritative; this reconciles already-seeded envs
 * where RoleSeeder's additive givePermissionTo cannot remove a stale grant):
 *
 *   Warehouse Manager
 *     − loses purchase-order authoring (create/update/submit/cancel/close) — she may
 *       still VIEW purchase orders (inventory.purchase.view) and record purchases.
 *     − loses recipe visibility (recipes are Admin-only).
 *     + gains category & unit management (the item taxonomy she curates).
 *     (Suppliers, locations, wastage-threshold settings and IMS role assignment stay
 *      out of scope — already revoked by WarehouseManagerCleanupSeeder / never granted.)
 *
 *   Purchasing Clerk
 *     + gains supplier management (the vendor list is a purchasing concern).
 *
 * Idempotent — revoke/grant are no-ops when the role already has the target state, so
 * it is safe to re-run.
 *
 * Run once per already-seeded environment (after PermissionSeeder + RoleSeeder):
 *   php artisan db:seed --class=WarehouseManagerCleanup2Seeder --force
 */
class WarehouseManagerCleanup2Seeder extends Seeder
{
    public function run(): void
    {
        $warehouseManager = Role::where('name', RoleEnum::WarehouseManager->value)
            ->where('guard_name', 'api')
            ->first();

        if ($warehouseManager) {
            $warehouseManager->revokePermissionTo([
                // Purchase-order authoring is Clerk/Admin — WM keeps view + purchase.create.
                Permission::InventoryPurchaseOrderCreate->value,
                Permission::InventoryPurchaseOrderUpdate->value,
                Permission::InventoryPurchaseOrderSubmit->value,
                Permission::InventoryPurchaseOrderCancel->value,
                Permission::InventoryPurchaseOrderClose->value,
                // Recipes are Admin-only ("I'll do the BOMs myself").
                Permission::InventoryRecipeView->value,
            ]);

            $warehouseManager->givePermissionTo([
                Permission::InventoryCategoryManage->value,
                Permission::InventoryUnitManage->value,
            ]);
        }

        $purchasingClerk = Role::where('name', RoleEnum::PurchasingClerk->value)
            ->where('guard_name', 'api')
            ->first();

        if ($purchasingClerk) {
            $purchasingClerk->givePermissionTo([
                Permission::InventorySupplierManage->value,
            ]);
        }
    }
}
