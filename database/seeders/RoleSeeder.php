<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Add permissions without removing any existing ones.
     *
     * @param  array<int, string>  $permissions
     */
    private function addPermissions(Role $role, array $permissions): void
    {
        $role->givePermissionTo($permissions);
    }

    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        // Platform-specific permissions that only tech_admin should have
        $platformPermissions = [
            Permission::AccessPlatformAdmin->value,
            Permission::ViewSystemHealth->value,
            Permission::ViewErrorLogs->value,
            Permission::ManageRoles->value,
            Permission::ResetPasswords->value,
            Permission::ManagePlatform->value,
            Permission::ManageCache->value,
            Permission::ToggleMaintenance->value,
        ];

        // Create Tech Admin role (IT/Tech — full access to everything including platform tools)
        $techAdmin = Role::updateOrCreate(
            ['name' => RoleEnum::TechAdmin->value, 'guard_name' => 'api'],
            ['name' => RoleEnum::TechAdmin->value, 'guard_name' => 'api']
        );
        $this->addPermissions($techAdmin,
            array_map(fn ($permission) => $permission->value, Permission::cases())
        );

        // Create Admin role (business owner — full business access, no platform tools)
        $admin = Role::updateOrCreate(
            ['name' => RoleEnum::Admin->value, 'guard_name' => 'api'],
            ['name' => RoleEnum::Admin->value, 'guard_name' => 'api']
        );
        $this->addPermissions($admin,
            array_filter(
                array_map(fn ($permission) => $permission->value, Permission::cases()),
                fn ($permission) => ! in_array($permission, $platformPermissions, true),
            )
        );

        // Create Branch Partner role (read-only investor access)
        $branchPartner = Role::updateOrCreate(
            ['name' => RoleEnum::BranchPartner->value, 'guard_name' => 'api'],
            ['name' => RoleEnum::BranchPartner->value, 'guard_name' => 'api']
        );
        $this->addPermissions($branchPartner, [
            Permission::ViewOrders->value,
            Permission::ViewMenu->value,
            Permission::ViewBranches->value,
            Permission::ViewCustomers->value,
            Permission::ViewEmployees->value,
            Permission::ViewAnalytics->value,
            Permission::AccessPartnerPortal->value,
        ]);

        // Create Manager role (branch operations)
        $manager = Role::updateOrCreate(
            ['name' => RoleEnum::Manager->value, 'guard_name' => 'api'],
            ['name' => RoleEnum::Manager->value, 'guard_name' => 'api']
        );
        $this->addPermissions($manager, [
            Permission::ViewOrders->value,
            Permission::CreateOrders->value,
            Permission::UpdateOrders->value,
            Permission::DeleteOrders->value,
            Permission::ViewMenu->value,
            Permission::ManageMenu->value,
            Permission::ViewBranches->value,
            Permission::ManageBranches->value,
            Permission::ViewCustomers->value,
            Permission::ManageCustomers->value,
            Permission::ViewEmployees->value,
            Permission::ManageEmployees->value,
            Permission::ViewAnalytics->value,
            Permission::AccessManagerPortal->value,
            Permission::AccessPos->value,
            Permission::AccessKitchen->value,
            Permission::AccessOrderManager->value,
            Permission::ManageShifts->value,
            Permission::ManageSettings->value,
            Permission::ViewMyShifts->value,
            // IMS — Branch Manager scope (own branch / satellite kitchen)
            Permission::AccessInventoryPortal->value,
            Permission::ViewInventoryCatalog->value,
            Permission::InventoryRequisitionCreate->value,
            Permission::InventoryRequisitionApprove->value,
            Permission::InventoryTransferCreate->value,
            Permission::InventoryTransferSend->value,
            Permission::InventoryTransferReceive->value,
            Permission::InventoryTransferDispute->value,
            Permission::InventoryWastageRecord->value,
            Permission::InventoryDailyClosingEnter->value,
            Permission::InventoryRecipeView->value,
            Permission::InventoryReportView->value,
        ]);

        // Create Call Center role (order placement)
        $callCenter = Role::updateOrCreate(
            ['name' => RoleEnum::CallCenter->value, 'guard_name' => 'api'],
            ['name' => RoleEnum::CallCenter->value, 'guard_name' => 'api']
        );
        $this->addPermissions($callCenter, [
            Permission::ViewOrders->value,
            Permission::CreateOrders->value,
            Permission::UpdateOrders->value,
            Permission::ViewMenu->value,
            Permission::ViewBranches->value,
            Permission::ViewCustomers->value,
            Permission::ManageCustomers->value,
            Permission::AccessSalesPortal->value,
            Permission::ViewMySales->value,
            Permission::ViewMyShifts->value,
            Permission::ManageShifts->value,
        ]);

        // Create Kitchen role (kitchen display system)
        $kitchen = Role::updateOrCreate(
            ['name' => RoleEnum::Kitchen->value, 'guard_name' => 'api'],
            ['name' => RoleEnum::Kitchen->value, 'guard_name' => 'api']
        );
        $this->addPermissions($kitchen, [
            Permission::ViewOrders->value,
            Permission::UpdateOrders->value,
            Permission::ViewMenu->value,
            Permission::AccessKitchen->value,
        ]);

        // Create Rider role (delivery)
        $rider = Role::updateOrCreate(
            ['name' => RoleEnum::Rider->value, 'guard_name' => 'api'],
            ['name' => RoleEnum::Rider->value, 'guard_name' => 'api']
        );
        $this->addPermissions($rider, [
            Permission::ViewOrders->value,
            Permission::UpdateOrders->value,
            Permission::ViewCustomers->value,
            Permission::AccessOrderManager->value,
        ]);

        // Create Sales Staff role (replaces legacy "employee")
        $salesStaff = Role::updateOrCreate(
            ['name' => RoleEnum::SalesStaff->value, 'guard_name' => 'api'],
            ['name' => RoleEnum::SalesStaff->value, 'guard_name' => 'api']
        );
        $this->addPermissions($salesStaff, [
            Permission::ViewOrders->value,
            Permission::CreateOrders->value,
            Permission::UpdateOrders->value,
            Permission::ViewMenu->value,
            Permission::ViewBranches->value,
            Permission::ViewCustomers->value,
            Permission::AccessSalesPortal->value,
            Permission::AccessPos->value,
            Permission::AccessKitchen->value,
            Permission::AccessOrderManager->value,
            Permission::ViewMySales->value,
            Permission::ViewMyShifts->value,
            Permission::ManageShifts->value,
        ]);

        // Create Warehouse Manager role (mother kitchen — full IMS warehouse-level control)
        $warehouseManager = Role::updateOrCreate(
            ['name' => RoleEnum::WarehouseManager->value, 'guard_name' => 'api'],
            ['name' => RoleEnum::WarehouseManager->value, 'guard_name' => 'api']
        );
        $this->addPermissions($warehouseManager, [
            Permission::AccessInventoryPortal->value,
            Permission::ViewInventoryCatalog->value,
            Permission::ManageInventoryCatalog->value,
            Permission::InventoryPurchaseCreate->value,
            Permission::InventoryPurchaseView->value,
            Permission::InventoryRequisitionCreate->value,
            Permission::InventoryRequisitionApprove->value,
            Permission::InventoryTransferCreate->value,
            Permission::InventoryTransferSend->value,
            Permission::InventoryTransferReceive->value,
            Permission::InventoryTransferDispute->value,
            Permission::InventoryTransferResolveDispute->value,
            Permission::InventoryWastageRecord->value,
            Permission::InventoryWastageApprove->value,
            Permission::InventoryDailyClosingEnter->value,
            Permission::InventoryRecipeView->value,
            Permission::InventoryReconciliationOpenCycle->value,
            Permission::InventoryReconciliationAdjust->value,
            Permission::InventoryReportView->value,
            Permission::InventorySettingsManage->value,
            // Cross-portal visibility for warehouse manager
            Permission::ViewBranches->value,
            Permission::ViewMenu->value,
        ]);

        // Create Purchasing Clerk role (records supplier purchases into the warehouse)
        $purchasingClerk = Role::updateOrCreate(
            ['name' => RoleEnum::PurchasingClerk->value, 'guard_name' => 'api'],
            ['name' => RoleEnum::PurchasingClerk->value, 'guard_name' => 'api']
        );
        $this->addPermissions($purchasingClerk, [
            Permission::AccessInventoryPortal->value,
            Permission::ViewInventoryCatalog->value,
            Permission::InventoryPurchaseCreate->value,
            Permission::InventoryPurchaseView->value,
            Permission::InventoryReportView->value,
        ]);

    }
}
