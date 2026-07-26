<?php

use App\Http\Controllers\Api\Inventory\CatalogController;
use App\Http\Controllers\Api\Inventory\DailyClosingController;
use App\Http\Controllers\Api\Inventory\ProductionController;
use App\Http\Controllers\Api\Inventory\ProductionRunController;
use App\Http\Controllers\Api\Inventory\PurchaseController;
use App\Http\Controllers\Api\Inventory\PurchaseOrderController;
use App\Http\Controllers\Api\Inventory\RecipeController;
use App\Http\Controllers\Api\Inventory\ReconciliationController;
use App\Http\Controllers\Api\Inventory\ReportController;
use App\Http\Controllers\Api\Inventory\RequisitionController;
use App\Http\Controllers\Api\Inventory\TransferController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inventory (IMS) Routes
|--------------------------------------------------------------------------
|
| Gated by `inventory.enabled` (feature flag) + Sanctum auth. Each write/action
| route is additionally gated by a Spatie permission. Super-admin roles
| (tech_admin / admin) inherit every IMS permission via the seeder, so they can
| exercise warehouse-manager-exclusive actions during development.
|
*/

Route::middleware(['auth:sanctum', 'inventory.enabled'])
    ->prefix('inventory')
    ->name('inventory.')
    ->group(function () {
        // ── Catalog (read-only) ──────────────────────────────────────────────
        Route::middleware('permission:view_inventory_catalog')->group(function () {
            Route::get('items', [CatalogController::class, 'items'])->name('items.index');
            Route::get('items/{item}/movements', [CatalogController::class, 'itemMovements'])->name('items.movements');
            Route::get('items/{item}', [CatalogController::class, 'item'])->name('items.show');
            Route::get('suppliers', [CatalogController::class, 'suppliers'])->name('suppliers.index');
            Route::get('suppliers/{supplier}', [CatalogController::class, 'supplier'])->name('suppliers.show');
            Route::get('units', [CatalogController::class, 'units'])->name('units.index');
            Route::get('categories', [CatalogController::class, 'categories'])->name('categories.index');
            Route::get('locations', [CatalogController::class, 'locations'])->name('locations.index');
            Route::get('locations/{location}', [CatalogController::class, 'location'])->name('locations.show');
        });

        // ── Catalog (writes) ─────────────────────────────────────────────────
        // Item master data — Warehouse Manager + Admin.
        Route::middleware('permission:manage_inventory_catalog')->group(function () {
            Route::post('items', [CatalogController::class, 'storeItem'])->name('items.store');
            Route::patch('items/{item}', [CatalogController::class, 'updateItem'])->name('items.update');
        });

        // Suppliers — a purchasing concern. Purchasing Clerk + Admin (NOT WM).
        Route::middleware('permission:inventory.supplier.manage')->group(function () {
            Route::post('suppliers', [CatalogController::class, 'storeSupplier'])->name('suppliers.store');
            Route::patch('suppliers/{supplier}', [CatalogController::class, 'updateSupplier'])->name('suppliers.update');
        });

        // Categories & units — item taxonomy the Warehouse Manager curates
        // alongside items. WM + Admin.
        Route::middleware('permission:inventory.category.manage')->group(function () {
            Route::post('categories', [CatalogController::class, 'storeCategory'])->name('categories.store');
        });
        Route::middleware('permission:inventory.unit.manage')->group(function () {
            Route::post('units', [CatalogController::class, 'storeUnit'])->name('units.store');
        });

        // Locations — structural warehouse topology. Admin-only setup; the
        // Warehouse Manager operates within it but does not define it.
        Route::middleware('permission:inventory.location.manage')->group(function () {
            Route::post('locations', [CatalogController::class, 'storeLocation'])->name('locations.store');
        });

        // ── Purchase Orders ──────────────────────────────────────────────────
        Route::prefix('purchase-orders')->name('purchase-orders.')->group(function () {
            Route::get('/', [PurchaseOrderController::class, 'index'])
                ->middleware('permission:inventory.purchase.view')->name('index');
            // Verify by QR/verification code — must precede the {purchaseOrder} wildcard.
            Route::get('verify/{code}', [PurchaseOrderController::class, 'verify'])
                ->middleware('permission:inventory.purchase.view')->name('verify');
            Route::get('{purchaseOrder}', [PurchaseOrderController::class, 'show'])
                ->middleware('permission:inventory.purchase.view')->name('show');

            Route::post('/', [PurchaseOrderController::class, 'store'])
                ->middleware('permission:inventory.purchase_order.create')->name('store');
            Route::patch('{purchaseOrder}', [PurchaseOrderController::class, 'update'])
                ->middleware('permission:inventory.purchase_order.update')->name('update');

            Route::post('{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])
                ->middleware('permission:inventory.purchase_order.submit')->name('submit');
            Route::post('{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])
                ->middleware('permission:inventory.purchase_order.approve')->name('approve');
            Route::post('{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])
                ->middleware('permission:inventory.purchase_order.cancel')->name('cancel');
            Route::post('{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])
                ->middleware('permission:inventory.purchase_order.close')->name('close');
        });

        // ── Purchases (receipts) ─────────────────────────────────────────────
        Route::prefix('purchases')->name('purchases.')->group(function () {
            Route::get('/', [PurchaseController::class, 'index'])
                ->middleware('permission:inventory.purchase.view')->name('index');
            Route::get('{purchase}', [PurchaseController::class, 'show'])
                ->middleware('permission:inventory.purchase.view')->name('show');
            Route::post('/', [PurchaseController::class, 'store'])
                ->middleware('permission:inventory.purchase.create')->name('store');
        });

        // ── Production (mother kitchen consumption / stock issue) ─────────────
        Route::prefix('production')->name('production.')->group(function () {
            Route::post('/', [ProductionController::class, 'store'])
                ->middleware('permission:inventory.production.record')->name('store');
        });

        // ── Production runs (batch-prep: consume inputs → yield prepared item) ─
        Route::prefix('production-runs')->name('production-runs.')->group(function () {
            Route::get('/', [ProductionRunController::class, 'index'])
                ->middleware('permission:inventory.production.record')->name('index');
            Route::post('/', [ProductionRunController::class, 'store'])
                ->middleware('permission:inventory.production.record')->name('store');
        });

        // ── Recipes / BOM ────────────────────────────────────────────────────
        Route::prefix('recipes')->name('recipes.')->group(function () {
            Route::get('/', [RecipeController::class, 'index'])
                ->middleware('permission:inventory.recipe.view')->name('index');
            Route::get('{recipe}', [RecipeController::class, 'show'])
                ->middleware('permission:inventory.recipe.view')->name('show');
            Route::post('/', [RecipeController::class, 'store'])
                ->middleware('permission:inventory.recipe.edit_global')->name('store');
            Route::patch('{recipe}', [RecipeController::class, 'update'])
                ->middleware('permission:inventory.recipe.edit_global')->name('update');
            Route::delete('{recipe}', [RecipeController::class, 'destroy'])
                ->middleware('permission:inventory.recipe.edit_global')->name('destroy');
            Route::post('{recipe}/lock', [RecipeController::class, 'lock'])
                ->middleware('permission:inventory.recipe.lock')->name('lock');
        });

        // ── Requisitions (branch stock requests → spawn a transfer on approve) ─
        Route::prefix('requisitions')->name('requisitions.')->group(function () {
            Route::get('/', [RequisitionController::class, 'index'])
                ->middleware('permission:view_inventory_catalog')->name('index');
            Route::get('{requisition}', [RequisitionController::class, 'show'])
                ->middleware('permission:view_inventory_catalog')->name('show');

            Route::post('/', [RequisitionController::class, 'store'])
                ->middleware('permission:inventory.requisition.create')->name('store');
            Route::patch('{requisition}', [RequisitionController::class, 'update'])
                ->middleware('permission:inventory.requisition.create')->name('update');
            Route::post('{requisition}/submit', [RequisitionController::class, 'submit'])
                ->middleware('permission:inventory.requisition.create')->name('submit');
            // Drafts only, author only — enforced in the service.
            Route::delete('{requisition}', [RequisitionController::class, 'destroy'])
                ->middleware('permission:inventory.requisition.create')->name('destroy');

            // Warehouse manager decides — approving spawns the fulfilling transfer.
            Route::post('{requisition}/approve', [RequisitionController::class, 'approve'])
                ->middleware('permission:inventory.requisition.approve')->name('approve');
            Route::post('{requisition}/reject', [RequisitionController::class, 'reject'])
                ->middleware('permission:inventory.requisition.approve')->name('reject');
        });

        // ── Transfers (stock movement between locations) ─────────────────────
        Route::prefix('transfers')->name('transfers.')->group(function () {
            Route::get('/', [TransferController::class, 'index'])
                ->middleware('permission:view_inventory_catalog')->name('index');
            // Pre-flight "does the source actually have this?" — must be declared
            // before {transfer} or the model binding swallows it.
            Route::post('availability', [TransferController::class, 'availability'])
                ->middleware('permission:view_inventory_catalog')->name('availability');
            Route::get('{transfer}', [TransferController::class, 'show'])
                ->middleware('permission:view_inventory_catalog')->name('show');

            Route::post('/', [TransferController::class, 'store'])
                ->middleware('permission:inventory.transfer.create')->name('store');
            Route::patch('{transfer}', [TransferController::class, 'update'])
                ->middleware('permission:inventory.transfer.create')->name('update');
            Route::post('{transfer}/submit', [TransferController::class, 'submit'])
                ->middleware('permission:inventory.transfer.create')->name('submit');
            Route::post('{transfer}/cancel', [TransferController::class, 'cancel'])
                ->middleware('permission:inventory.transfer.create')->name('cancel');

            // Release authority — approving + sending stock out of the source.
            Route::post('{transfer}/approve', [TransferController::class, 'approve'])
                ->middleware('permission:inventory.transfer.send')->name('approve');
            Route::post('{transfer}/send', [TransferController::class, 'send'])
                ->middleware('permission:inventory.transfer.send')->name('send');

            Route::post('{transfer}/receive', [TransferController::class, 'receive'])
                ->middleware('permission:inventory.transfer.receive')->name('receive');
            Route::post('{transfer}/resolve-dispute', [TransferController::class, 'resolveDispute'])
                ->middleware('permission:inventory.transfer.resolve_dispute')->name('resolve-dispute');
        });

        // ── Daily closing (mandatory end-of-day count → variance) ────────────
        Route::prefix('daily-closings')->name('daily-closings.')->group(function () {
            Route::get('/', [DailyClosingController::class, 'index'])
                ->middleware('permission:view_inventory_catalog')->name('index');
            // Calendar (missed-day coverage) — must precede the {dailyClosing} wildcard.
            Route::get('calendar', [DailyClosingController::class, 'calendar'])
                ->middleware('permission:view_inventory_catalog')->name('calendar');
            Route::get('{dailyClosing}', [DailyClosingController::class, 'show'])
                ->middleware('permission:view_inventory_catalog')->name('show');

            Route::post('/', [DailyClosingController::class, 'store'])
                ->middleware('permission:inventory.daily_closing.enter')->name('store');
            Route::patch('{dailyClosing}', [DailyClosingController::class, 'update'])
                ->middleware('permission:inventory.daily_closing.enter')->name('update');
        });

        // ── Reconciliation (stock-take → post cycle_adjustment → reset books) ─
        Route::prefix('reconciliations')->name('reconciliations.')->group(function () {
            Route::get('/', [ReconciliationController::class, 'index'])
                ->middleware('permission:view_inventory_catalog')->name('index');
            Route::get('{reconciliation}', [ReconciliationController::class, 'show'])
                ->middleware('permission:view_inventory_catalog')->name('show');

            // Opening + counting is the warehouse manager's stock-take.
            Route::post('/', [ReconciliationController::class, 'store'])
                ->middleware('permission:inventory.reconciliation.open_cycle')->name('store');
            Route::patch('{reconciliation}', [ReconciliationController::class, 'update'])
                ->middleware('permission:inventory.reconciliation.open_cycle')->name('update');

            // Posting writes cycle_adjustment movements — the adjust authority.
            Route::post('{reconciliation}/post', [ReconciliationController::class, 'post'])
                ->middleware('permission:inventory.reconciliation.adjust')->name('post');
        });

        // ── Reports ──────────────────────────────────────────────────────────
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('expiring-batches', [ReportController::class, 'expiringBatches'])
                ->middleware('permission:inventory.report.view')->name('expiring-batches');
        });
    });
