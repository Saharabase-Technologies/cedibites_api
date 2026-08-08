<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\Admin\SmartCategorySettingController;
use App\Http\Controllers\Api\AdminAnalyticsController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminReportController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\CancelRequestController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\MenuBranchAvailabilityController;
use App\Http\Controllers\Api\MenuCategoryController;
use App\Http\Controllers\Api\MenuItemController;
use App\Http\Controllers\Api\MenuItemOptionController;
use App\Http\Controllers\Api\MenuTagController;
use App\Http\Controllers\Api\OrderFeedbackController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\RecruitmentAdminController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\ShortLinkController;
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

        // Recruitment. Same gate as hiring by hand, because approving an
        // application *is* hiring — the account is created at that moment. That
        // deliberately leaves the manager out: he does not hold
        // `manage_employees` (see RoleSeeder), and since a branch posting can
        // appoint a manager, letting him approve would reopen the role ceiling
        // by another door.
        //
        // The controller still scopes every query by branch. Nothing
        // branch-confined can reach these routes today, so that scoping is
        // insurance: the day anyone grants `manage_employees` to a branch role,
        // it is the difference between seeing one branch's applicants and
        // reading every HR record in the company.
        Route::get('recruitment-links', [RecruitmentAdminController::class, 'links']);
        Route::post('recruitment-links', [RecruitmentAdminController::class, 'createLink']);
        // Label and closing date only — the kind and branch cannot move once the
        // URL is out there. See UpdateRecruitmentLinkRequest.
        Route::patch('recruitment-links/{link}', [RecruitmentAdminController::class, 'updateLink']);
        Route::delete('recruitment-links/{link}', [RecruitmentAdminController::class, 'deleteLink']);
        Route::get('recruitment-applications', [RecruitmentAdminController::class, 'applications']);
        Route::get('recruitment-applications/{application}', [RecruitmentAdminController::class, 'showApplication']);
        Route::post('recruitment-applications/{application}/approve', [RecruitmentAdminController::class, 'approve']);
        Route::post('recruitment-applications/{application}/reject', [RecruitmentAdminController::class, 'reject']);
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

    // Short links and SMS campaigns. Same ceiling as the contact export, by the
    // same reasoning — see Permission::ManageCampaigns. Every write is
    // activity-logged on the model: a link is our brand pointed at somebody's
    // URL, and a campaign is the company speaking to every customer at once.
    Route::middleware('permission:manage_campaigns')->group(function () {
        Route::get('links', [ShortLinkController::class, 'index']);
        Route::post('links', [ShortLinkController::class, 'store']);
        Route::patch('links/{link}', [ShortLinkController::class, 'update']);
        Route::delete('links/{link}', [ShortLinkController::class, 'destroy']);

        // Declared before the {campaign} routes so the wildcard does not
        // swallow them.
        Route::get('campaigns/segments', [CampaignController::class, 'segments']);
        Route::post('campaigns/measure', [CampaignController::class, 'measure']);

        // The audience builder. `options` is what it can filter on;
        // `count-audience` is how many people the current rules match, called
        // as the operator assembles them.
        Route::get('campaigns/audience-options', [CampaignController::class, 'audienceOptions']);
        Route::post('campaigns/count-audience', [CampaignController::class, 'countAudience']);

        Route::get('campaigns', [CampaignController::class, 'index']);
        Route::post('campaigns', [CampaignController::class, 'store']);
        Route::get('campaigns/{campaign}', [CampaignController::class, 'show']);
        Route::patch('campaigns/{campaign}', [CampaignController::class, 'update']);
        Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy']);

        // The two-step send. `preview` is the confirm screen — recipient count,
        // characters, billed segments, projected cost — and `send` is the only
        // call in the application that spends money on SMS in bulk.
        Route::get('campaigns/{campaign}/preview', [CampaignController::class, 'preview']);
        Route::post('campaigns/{campaign}/send', [CampaignController::class, 'send']);
        Route::post('campaigns/{campaign}/cancel', [CampaignController::class, 'cancel']);

        // What customers said about their orders. Not marketing, but gated with
        // it deliberately: the list is company-wide and the controller does no
        // branch scoping, so opening it to a branch role would hand one manager
        // every other branch's complaints. Giving a manager their own branch's
        // feedback is worth doing and needs the isCompanyWide() treatment first
        // — see the branch-isolation notes before widening this.
        Route::get('customer-feedback', [OrderFeedbackController::class, 'index']);

        /*
         * The supplementary contact base — imported numbers that have bought
         * nothing.
         *
         * Gated here rather than under `view_customers` even though the UI shows
         * it as a tab beside the customer list. The whole table is names and
         * numbers in bulk, which is exactly what the export ceiling above
         * exists to protect; a cashier who can look up one caller should not be
         * able to page through an uploaded list of 28,000.
         *
         * Fixed segments declared before `{import}` so the wildcard does not
         * swallow them.
         */
        Route::get('contacts/stats', [ContactController::class, 'stats']);
        Route::get('contacts/conversions', [ContactController::class, 'conversions']);
        Route::get('contacts/imports', [ContactController::class, 'imports']);
        Route::post('contacts/import/preview', [ContactController::class, 'preview']);
        Route::post('contacts/import', [ContactController::class, 'store']);
        Route::delete('contacts/imports/{import}', [ContactController::class, 'undoImport']);

        Route::get('contacts', [ContactController::class, 'index']);
        Route::delete('contacts/{contact}', [ContactController::class, 'destroy']);
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

        // Literal segments before the {menuItem} wildcard, or the wildcard eats them.
        Route::get('menu-items', [MenuItemController::class, 'adminIndex']);
        Route::get('menu-items/branch-availability', [MenuBranchAvailabilityController::class, 'index']);
        Route::post('menu-items', [MenuItemController::class, 'store']);
        Route::post('menu-items/bulk-import-preview', [MenuItemController::class, 'bulkImportPreview']);
        Route::post('menu-items/bulk-import', [MenuItemController::class, 'bulkImport']);
        Route::patch('menu-items/{menuItem}', [MenuItemController::class, 'update']);
        Route::delete('menu-items/{menuItem}', [MenuItemController::class, 'destroy']);

        // Availability is the only thing that varies by branch. Replaces the
        // sibling-row branch-overrides pair, which matched nothing after
        // menu:unify and reported success while writing nothing.
        Route::patch('menu-items/{menuItem}/branches/{branch}', [MenuBranchAvailabilityController::class, 'update']);
        Route::patch('menu-items/{menuItem}/branches', [MenuBranchAvailabilityController::class, 'updateAll']);

        Route::get('menu-items/{menuItem}/options', [MenuItemOptionController::class, 'index']);
        Route::post('menu-items/{menuItem}/options', [MenuItemOptionController::class, 'store']);
        Route::get('menu-items/{menuItem}/options/{option}', [MenuItemOptionController::class, 'show']);
        Route::patch('menu-items/{menuItem}/options/{option}', [MenuItemOptionController::class, 'update']);
        Route::delete('menu-items/{menuItem}/options/{option}', [MenuItemOptionController::class, 'destroy']);
        Route::post('menu-items/{menuItem}/options/{option}/image', [MenuItemOptionController::class, 'uploadImage']);
        Route::delete('menu-items/{menuItem}/options/{option}/image', [MenuItemOptionController::class, 'deleteImage']);

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
