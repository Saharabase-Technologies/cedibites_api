<?php

use App\Http\Controllers\Api\Inventory\CatalogController;
use App\Http\Controllers\Api\Inventory\ProductionController;
use App\Http\Controllers\Api\Inventory\PurchaseController;
use App\Http\Controllers\Api\Inventory\PurchaseOrderController;
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
        Route::middleware('permission:manage_inventory_catalog')->group(function () {
            Route::post('suppliers', [CatalogController::class, 'storeSupplier'])->name('suppliers.store');
            Route::patch('suppliers/{supplier}', [CatalogController::class, 'updateSupplier'])->name('suppliers.update');
            Route::post('categories', [CatalogController::class, 'storeCategory'])->name('categories.store');
            Route::post('units', [CatalogController::class, 'storeUnit'])->name('units.store');
            Route::post('items', [CatalogController::class, 'storeItem'])->name('items.store');
            Route::patch('items/{item}', [CatalogController::class, 'updateItem'])->name('items.update');
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
    });
