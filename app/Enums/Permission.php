<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

enum Permission: string
{
    use HasEnumHelpers;

    // Order permissions
    case ViewOrders = 'view_orders';
    case CreateOrders = 'create_orders';
    case UpdateOrders = 'update_orders';
    case DeleteOrders = 'delete_orders';

    // Menu permissions
    case ViewMenu = 'view_menu';
    case ManageMenu = 'manage_menu';

    // Branch permissions
    case ViewBranches = 'view_branches';
    case ManageBranches = 'manage_branches';

    // Customer permissions
    case ViewCustomers = 'view_customers';
    case ManageCustomers = 'manage_customers';

    // Employee permissions
    case ViewEmployees = 'view_employees';
    case ManageEmployees = 'manage_employees';

    // Analytics permissions
    case ViewAnalytics = 'view_analytics';

    // Audit
    case ViewActivityLog = 'view_activity_log';

    // Portal access
    case AccessAdminPanel = 'access_admin_panel';
    case AccessManagerPortal = 'access_manager_portal';
    case AccessSalesPortal = 'access_sales_portal';
    case AccessPartnerPortal = 'access_partner_portal';
    case AccessPos = 'access_pos';
    case AccessKitchen = 'access_kitchen';
    case AccessOrderManager = 'access_order_manager';

    // Feature flags (nav gating within portals)
    case ManageShifts = 'manage_shifts';
    case ManageSettings = 'manage_settings';
    case ViewMyShifts = 'view_my_shifts';
    case ViewMySales = 'view_my_sales';

    // Platform Admin permissions
    case AccessPlatformAdmin = 'access_platform_admin';
    case ViewSystemHealth = 'view_system_health';
    case ViewErrorLogs = 'view_error_logs';
    case ManageRoles = 'manage_roles';
    case ResetPasswords = 'reset_passwords';
    case ManagePlatform = 'manage_platform';
    case ManageCache = 'manage_cache';
    case ToggleMaintenance = 'toggle_maintenance';

    // Inventory (IMS) — portal access
    case AccessInventoryPortal = 'access_inventory_portal';

    // Inventory — catalog (Phase 0 CRUD)
    case ManageInventoryCatalog = 'manage_inventory_catalog';
    case ViewInventoryCatalog = 'view_inventory_catalog';

    // Inventory — purchase orders
    case InventoryPurchaseOrderCreate = 'inventory.purchase_order.create';
    case InventoryPurchaseOrderUpdate = 'inventory.purchase_order.update';
    case InventoryPurchaseOrderSubmit = 'inventory.purchase_order.submit';
    case InventoryPurchaseOrderApprove = 'inventory.purchase_order.approve';
    case InventoryPurchaseOrderCancel = 'inventory.purchase_order.cancel';
    case InventoryPurchaseOrderClose = 'inventory.purchase_order.close';

    // Inventory — purchases
    case InventoryPurchaseCreate = 'inventory.purchase.create';
    case InventoryPurchaseView = 'inventory.purchase.view';
    case InventoryPurchaseUrgentBuy = 'inventory.purchase.urgent_buy';

    // Inventory — requisitions
    case InventoryRequisitionCreate = 'inventory.requisition.create';
    case InventoryRequisitionApprove = 'inventory.requisition.approve';

    // Inventory — transfers
    case InventoryTransferCreate = 'inventory.transfer.create';
    case InventoryTransferSend = 'inventory.transfer.send';
    case InventoryTransferReceive = 'inventory.transfer.receive';
    case InventoryTransferDispute = 'inventory.transfer.dispute';
    case InventoryTransferResolveDispute = 'inventory.transfer.resolve_dispute';
    case InventoryTransferOverrideSourceCheck = 'inventory.transfer.override_source_check';

    // Inventory — production (mother kitchen consuming raw materials)
    case InventoryProductionRecord = 'inventory.production.record';

    // Inventory — wastage
    case InventoryWastageRecord = 'inventory.wastage.record';
    case InventoryWastageApprove = 'inventory.wastage.approve';

    // Inventory — daily closing
    case InventoryDailyClosingEnter = 'inventory.daily_closing.enter';

    // Inventory — recipes
    case InventoryRecipeView = 'inventory.recipe.view';
    case InventoryRecipeEditGlobal = 'inventory.recipe.edit_global';
    case InventoryRecipeOverridePerBranch = 'inventory.recipe.override_per_branch';
    case InventoryRecipeLock = 'inventory.recipe.lock';

    // Inventory — reconciliation
    case InventoryReconciliationOpenCycle = 'inventory.reconciliation.open_cycle';
    case InventoryReconciliationAdjust = 'inventory.reconciliation.adjust';

    // Inventory — reports & settings
    case InventoryReportView = 'inventory.report.view';
    case InventorySettingsManage = 'inventory.settings.manage';

    // Inventory — visibility scope.
    // Absent this, a user's inventory reads are confined to the locations
    // belonging to their assigned branches (see User::accessibleLocationIds).
    case InventoryViewAllLocations = 'inventory.view_all_locations';
}
