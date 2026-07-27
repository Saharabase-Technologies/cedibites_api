<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\Admin\SmartCategorySettingController;
use App\Http\Controllers\Api\AdminAnalyticsController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminReportController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CancelRequestController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\MenuAddOnController;
use App\Http\Controllers\Api\MenuCategoryController;
use App\Http\Controllers\Api\MenuItemBranchOptionController;
use App\Http\Controllers\Api\MenuItemController;
use App\Http\Controllers\Api\MenuItemOptionController;
use App\Http\Controllers\Api\MenuTagController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\RoleController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->group(function () {
    // Same reasoning as analytics below: the dashboard is revenue and live orders
    // across every branch, which is a reporting surface, not an order-handling one.
    Route::middleware('permission:view_analytics')->group(function () {
        Route::get('dashboard', [AdminDashboardController::class, 'index']);
    });

    Route::middleware('permission:view_employees')->group(function () {
        Route::get('employees/sessions/active', [EmployeeController::class, 'activeSessions']);
        Route::get('employees', [EmployeeController::class, 'index']);
        Route::get('employees/{employee}', [EmployeeController::class, 'show']);
    });

    // Staff notes are the branch manager's one remaining power over his people:
    // a private running record, with no ability to hire, change a role, or
    // suspend anyone's access. Gated on `employee.notes.manage` rather than
    // `manage_employees` so the manager keeps this while losing the rest, and
    // scoped in the controller to employees who share one of his branches.
    // Editing and deleting are restricted to the note's own author.
    Route::middleware('permission:employee.notes.manage')->group(function () {
        Route::get('employees/{employee}/notes', [EmployeeController::class, 'notes']);
        Route::post('employees/{employee}/notes', [EmployeeController::class, 'addNote']);
        Route::patch('employees/{employee}/notes/{note}', [EmployeeController::class, 'updateNote']);
        Route::delete('employees/{employee}/notes/{note}', [EmployeeController::class, 'deleteNote']);
    });

    Route::middleware('permission:manage_employees')->group(function () {
        Route::post('employees', [EmployeeController::class, 'store']);
        Route::patch('employees/{employee}', [EmployeeController::class, 'update']);
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy']);
        Route::post('employees/{employee}/force-logout', [EmployeeController::class, 'forceLogout']);
        Route::post('employees/{employee}/require-password-reset', [EmployeeController::class, 'requirePasswordReset']);

        // Role and permission endpoints for staff management
        Route::get('roles', [RoleController::class, 'index']);
        Route::get('permissions', [RoleController::class, 'permissions']);
    });

    // Bulk contact export is the whole customer database in one call — name and
    // phone for every registered customer and every guest who ever ordered. That
    // is an admin act, not a `view_customers` one: sales staff, call centre,
    // riders, managers and partners all hold `view_customers` so they can look a
    // caller up, and none of them should be able to walk out with the list.
    // Declared before the `{customer}` routes below so it is not swallowed by the
    // wildcard.
    Route::middleware('role:admin|tech_admin')->group(function () {
        Route::get('customers/export-contacts', [CustomerController::class, 'exportContacts']);
    });

    Route::middleware('permission:view_customers')->group(function () {
        Route::get('customers', [CustomerController::class, 'index']);
        Route::get('customers/{customer}', [CustomerController::class, 'show']);
        Route::get('customers/{customer}/orders', [CustomerController::class, 'orders']);
    });

    Route::middleware('permission:manage_customers')->group(function () {
        Route::post('customers', [CustomerController::class, 'store']);
        Route::patch('customers/{customer}', [CustomerController::class, 'update']);
        Route::delete('customers/{customer}', [CustomerController::class, 'destroy']);
        Route::patch('customers/{customer}/suspend', [CustomerController::class, 'suspend']);
        Route::patch('customers/{customer}/unsuspend', [CustomerController::class, 'unsuspend']);
        Route::post('customers/{customer}/force-logout', [CustomerController::class, 'forceLogout']);
    });

    Route::middleware('permission:view_branches')->group(function () {
        Route::get('branches', [BranchController::class, 'index']);
        Route::get('branches/basic', [BranchController::class, 'basic']);
        Route::get('branches/{branch}', [BranchController::class, 'show']);
        Route::get('branches/{branch}/employees', [BranchController::class, 'employees']);
        Route::get('branches/{branch}/orders', [BranchController::class, 'orders']);
        Route::get('branches/{branch}/stats', [BranchController::class, 'stats']);
    });

    Route::middleware('permission:manage_branches')->group(function () {
        Route::post('branches', [BranchController::class, 'store']);
        Route::patch('branches/{branch}', [BranchController::class, 'update']);
        Route::delete('branches/{branch}', [BranchController::class, 'destroy']);
        Route::patch('branches/{branch}/toggle-status', [BranchController::class, 'toggleDailyStatus']);
        Route::delete('branches/{branch}/manual-override', [BranchController::class, 'clearManualOverride']);
        Route::patch('branches/{branch}/toggle-extended-staff-access', [BranchController::class, 'toggleExtendedStaffAccess']);
        Route::patch('branches/{branch}/toggle-extended-order-access', [BranchController::class, 'toggleExtendedOrderAccess']);
    });

    Route::middleware('permission:manage_menu')->group(function () {
        Route::apiResource('menu-categories', MenuCategoryController::class);
    });

    Route::middleware('permission:manage_menu')->group(function () {
        Route::apiResource('menu-tags', MenuTagController::class);
        Route::apiResource('menu-add-ons', MenuAddOnController::class);

        Route::post('menu-items', [MenuItemController::class, 'store']);
        Route::post('menu-items/bulk-import-preview', [MenuItemController::class, 'bulkImportPreview']);
        Route::post('menu-items/bulk-import', [MenuItemController::class, 'bulkImport']);
        Route::patch('menu-items/{menuItem}', [MenuItemController::class, 'update']);
        Route::delete('menu-items/{menuItem}', [MenuItemController::class, 'destroy']);
        Route::get('menu-items/{menuItem}/branch-overrides', [MenuItemBranchOptionController::class, 'show']);
        Route::put('menu-items/{menuItem}/branch-options', [MenuItemBranchOptionController::class, 'update']);

        Route::get('menu-items/{menuItem}/options', [MenuItemOptionController::class, 'index']);
        Route::post('menu-items/{menuItem}/options', [MenuItemOptionController::class, 'store']);
        Route::get('menu-items/{menuItem}/options/{option}', [MenuItemOptionController::class, 'show']);
        Route::patch('menu-items/{menuItem}/options/{option}', [MenuItemOptionController::class, 'update']);
        Route::delete('menu-items/{menuItem}/options/{option}', [MenuItemOptionController::class, 'destroy']);
        Route::post('menu-items/{menuItem}/options/{option}/image', [MenuItemOptionController::class, 'uploadImage']);

        // Smart category settings
        Route::get('smart-categories', [SmartCategorySettingController::class, 'index']);
        Route::patch('smart-categories/{smartCategorySetting}', [SmartCategorySettingController::class, 'update']);
        Route::post('smart-categories/reorder', [SmartCategorySettingController::class, 'reorder']);
        Route::get('smart-categories/{smartCategorySetting}/preview', [SmartCategorySettingController::class, 'preview']);
        Route::post('smart-categories/warm-cache', [SmartCategorySettingController::class, 'warmCache']);
        Route::post('smart-categories/{smartCategorySetting}/reset', [SmartCategorySettingController::class, 'resetToDefault']);
    });

    // Payments are financial reporting, not order handling. `view_orders` is held
    // by every staff role including the cashier, so gating on it put the whole
    // company's payment ledger behind a till login. `view_analytics` is the
    // permission that was always meant for this — admin, tech_admin, manager and
    // branch partner. The controller scopes the rows to the caller's branches.
    Route::middleware('permission:view_analytics')->group(function () {
        Route::get('payments', [PaymentController::class, 'index']);
        Route::get('payments/stats', [PaymentController::class, 'stats']);
        Route::get('payments/{payment}', [PaymentController::class, 'show']);
    });

    Route::middleware('permission:update_orders')->group(function () {
        Route::post('payments/{payment}/refund', [PaymentController::class, 'refund']);
    });

    Route::middleware('permission:view_activity_log')->group(function () {
        Route::get('activity-logs', [ActivityLogController::class, 'index']);
        Route::get('activity-logs/causers', [ActivityLogController::class, 'causers']);
    });

    // Analytics and reports are gated on `view_analytics`, NOT `view_orders`.
    // `view_orders` is held by sales staff, kitchen, riders and call centre, so
    // gating these on it meant any till login could pull company-wide revenue,
    // every colleague's sales figures and the branch league table. The correct
    // permission already existed and was already granted to exactly the right
    // roles — it just was not being used anywhere. AdminAnalyticsController
    // confines every figure to the caller's own branches on top of this.
    Route::middleware('permission:view_analytics')->group(function () {
        Route::prefix('analytics')->group(function () {
            Route::get('sales', [AdminAnalyticsController::class, 'sales']);
            Route::get('sales-comparison', [AdminAnalyticsController::class, 'salesComparison']);
            Route::get('revenue-trend', [AdminAnalyticsController::class, 'revenueTrend']);
            Route::get('orders', [AdminAnalyticsController::class, 'orders']);
            Route::get('customers', [AdminAnalyticsController::class, 'customers']);
            Route::get('order-sources', [AdminAnalyticsController::class, 'orderSources']);
            Route::get('top-items', [AdminAnalyticsController::class, 'topItems']);
            Route::get('bottom-items', [AdminAnalyticsController::class, 'bottomItems']);
            Route::get('category-revenue', [AdminAnalyticsController::class, 'categoryRevenue']);
            Route::get('branch-performance', [AdminAnalyticsController::class, 'branchPerformance']);
            Route::get('delivery-pickup', [AdminAnalyticsController::class, 'deliveryPickup']);
            Route::get('payment-methods', [AdminAnalyticsController::class, 'paymentMethods']);
            Route::get('fulfillment', [AdminAnalyticsController::class, 'fulfillment']);
            Route::get('promos', [AdminAnalyticsController::class, 'promos']);
            Route::get('discount-usage', [AdminAnalyticsController::class, 'discountUsage']);
            Route::get('cancellation-reasons', [AdminAnalyticsController::class, 'cancellationReasons']);
            Route::get('checkout-funnel', [AdminAnalyticsController::class, 'checkoutFunnel']);
            Route::get('staff-sales', [AdminAnalyticsController::class, 'staffSales']);
            Route::get('repeat-customers', [AdminAnalyticsController::class, 'repeatCustomers']);
            Route::get('customer-lifecycle', [AdminAnalyticsController::class, 'customerLifecycle']);
            Route::get('basket-affinity', [AdminAnalyticsController::class, 'basketAffinity']);
            Route::get('demand-forecast', [AdminAnalyticsController::class, 'demandForecast']);
            Route::get('weekday-hour', [AdminAnalyticsController::class, 'weekdayHour']);
            Route::get('menu-catalog', [AdminAnalyticsController::class, 'menuCatalog']);
            Route::post('menu-comparison', [AdminAnalyticsController::class, 'menuComparison']);
            Route::get('revenue-targets', [AdminAnalyticsController::class, 'getRevenueTargets']);
            Route::put('revenue-targets', [AdminAnalyticsController::class, 'setRevenueTarget']);
            Route::get('targets-vs-actual', [AdminAnalyticsController::class, 'targetsVsActual']);
        });

        Route::prefix('reports')->group(function () {
            Route::get('daily', [AdminReportController::class, 'daily']);
            Route::get('monthly', [AdminReportController::class, 'monthly']);
        });
    });

    // Cancel management (admin only)
    Route::middleware('role:admin|tech_admin')->group(function () {
        Route::post('orders/{order}/approve-cancel', [CancelRequestController::class, 'approveCancel']);
        Route::post('orders/{order}/reject-cancel', [CancelRequestController::class, 'rejectCancel']);
        Route::post('orders/{order}/cancel', [CancelRequestController::class, 'directCancel']);
        Route::post('orders/{order}/notes', [\App\Http\Controllers\Api\OrderNoteController::class, 'store']);
    });

    // System settings (admin only)
    Route::middleware('role:admin|tech_admin')->prefix('settings')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Admin\SystemSettingController::class, 'index']);
        Route::get('{key}', [\App\Http\Controllers\Api\Admin\SystemSettingController::class, 'show']);
        Route::put('{key}', [\App\Http\Controllers\Api\Admin\SystemSettingController::class, 'update']);
        Route::post('/', [\App\Http\Controllers\Api\Admin\SystemSettingController::class, 'store']);
    });
});
