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

        // Create Manager role (branch operations).
        // Scope: the manager runs a branch, he does not run the business. Every
        // branch is the same institution behind a different till, so the menu, the
        // prices and the staff roster are company-level and belong to the Admin. He
        // keeps what running a shift actually needs — taking and advancing orders,
        // flipping a dish off when the kitchen runs out, opening and closing his own
        // branch, and a private record on his own people.
        //
        // Deliberately NOT granted:
        //   ManageMenu      — one menu across every branch; editing it is company-wide.
        //                     He gets MenuAvailabilityManage instead. Prices are Admin's.
        //   ManageEmployees — no hiring, no role changes, no suspending access.
        //                     He gets EmployeeNotesManage instead.
        //   ManageBranches  — creating and deleting branches is Admin's.
        //                     He gets BranchOperate for his own branch instead.
        //   DeleteOrders    — a deleted order drops out of revenue. Cancellation
        //                     already has a request-and-approve flow; he uses that.
        // See ManagerScopeCleanupSeeder for revoking these on environments seeded
        // before this scoping — addPermissions only ever adds.
        $manager = Role::updateOrCreate(
            ['name' => RoleEnum::Manager->value, 'guard_name' => 'api'],
            ['name' => RoleEnum::Manager->value, 'guard_name' => 'api']
        );
        $this->addPermissions($manager, [
            Permission::ViewOrders->value,
            Permission::CreateOrders->value,
            Permission::UpdateOrders->value,
            Permission::OrderCancelRequest->value,
            Permission::ViewMenu->value,
            Permission::MenuAvailabilityManage->value,
            Permission::ViewBranches->value,
            Permission::BranchOperate->value,
            Permission::ViewCustomers->value,
            Permission::ManageCustomers->value,
            Permission::ViewEmployees->value,
            Permission::EmployeeNotesManage->value,
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
            // The stock gate's exception. A cashier facing a customer cannot
            // wave a sale through; the manager can, and it is logged.
            Permission::InventoryStockGateOverride->value,
        ]);

        // Create Call Center role (order placement).
        // Scope: they pick up the phone, talk to the customer, and place the
        // order against the branch that will cook it. That is the whole job —
        // from the moment it is placed the order belongs to that branch, and the
        // branch moves it through the kitchen. So no `update_orders`: the call
        // centre does not accept, prepare or complete anything. When a customer
        // rings back to cancel they raise a request and an admin decides.
        //
        // The role is company-wide (Role::branchRule) because the branch is a
        // property of each order, not of the agent — they take a call for
        // Ashaiman and the next one for Spintex.
        //
        // See CallCenterScopeCleanupSeeder for revoking `update_orders` on
        // environments seeded before this scoping — addPermissions only adds.
        $callCenter = Role::updateOrCreate(
            ['name' => RoleEnum::CallCenter->value, 'guard_name' => 'api'],
            ['name' => RoleEnum::CallCenter->value, 'guard_name' => 'api']
        );
        $this->addPermissions($callCenter, [
            Permission::ViewOrders->value,
            Permission::CreateOrders->value,
            Permission::OrderCancelRequest->value,
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
            // The kitchen finds out first when an order cannot be made.
            Permission::OrderCancelRequest->value,
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
            // A rider who cannot reach the customer raises the request.
            Permission::OrderCancelRequest->value,
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
            Permission::OrderCancelRequest->value,
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

        // Create Warehouse Manager role (mother kitchen — warehouse-level operations).
        // Scope (Warehouse Manager Cleanup 2): the WM owns the mother kitchen and its
        // day-to-day stock operations — transfers, requisitions, wastage, closing,
        // reconciliation, production, recording purchases — plus the item catalog she
        // curates (items, categories, units). She does NOT touch Admin/Clerk concerns:
        // suppliers, purchase-order authoring, recipes, structural setup (locations),
        // wastage-threshold settings, or IMS role assignment. She may VIEW purchase
        // orders (inventory.purchase.view) but not create/edit them.
        // See WarehouseManagerCleanup2Seeder for reconciling these grants on
        // environments that were seeded before this scoping.
        $warehouseManager = Role::updateOrCreate(
            ['name' => RoleEnum::WarehouseManager->value, 'guard_name' => 'api'],
            ['name' => RoleEnum::WarehouseManager->value, 'guard_name' => 'api']
        );
        $this->addPermissions($warehouseManager, [
            Permission::AccessInventoryPortal->value,
            Permission::ViewInventoryCatalog->value,
            // Item catalog the WM curates — items plus their taxonomy (categories,
            // units). Suppliers and locations are intentionally NOT here.
            Permission::ManageInventoryCatalog->value,
            Permission::InventoryCategoryManage->value,
            Permission::InventoryUnitManage->value,
            // Purchase orders are VIEW-only for the WM (authoring is Clerk/Admin).
            // She still records the actual receipts (purchases) into the warehouse.
            Permission::InventoryPurchaseCreate->value,
            Permission::InventoryPurchaseView->value,
            // Production — mother kitchen consuming raw materials
            Permission::InventoryProductionRecord->value,
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
            Permission::InventoryReconciliationOpenCycle->value,
            Permission::InventoryReconciliationAdjust->value,
            Permission::InventoryReportView->value,
            // NOTE: intentionally NOT granted —
            //   InventorySupplierManage / InventoryLocationManage — Admin/Clerk concerns;
            //   InventoryPurchaseOrder* (authoring) — Clerk/Admin;
            //   InventoryRecipeView + recipe authoring — Admin ("I'll do the BOMs myself");
            //   InventorySettingsManage — wastage-threshold + IMS role assignment (Admin).
            // Warehouse manager oversees every location, not just one branch.
            Permission::InventoryViewAllLocations->value,
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
            // Suppliers are a purchasing concern — the Clerk maintains the vendor list.
            Permission::InventorySupplierManage->value,
            // Clerk authors and manages POs; Admin still approves the >= ₵10k gate.
            Permission::InventoryPurchaseOrderCreate->value,
            Permission::InventoryPurchaseOrderUpdate->value,
            Permission::InventoryPurchaseOrderSubmit->value,
            Permission::InventoryPurchaseOrderCancel->value,
            Permission::InventoryPurchaseCreate->value,
            Permission::InventoryPurchaseView->value,
            // Clerk may record an ad-hoc receipt without a PO (emergency / market buy)
            Permission::InventoryPurchaseUrgentBuy->value,
            Permission::InventoryReportView->value,
            // Clerk buys into every location, so purchasing is not branch-confined.
            Permission::InventoryViewAllLocations->value,
        ]);

    }
}
