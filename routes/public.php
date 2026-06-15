<?php

use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\EmployeeAuthController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MenuCategoryController;
use App\Http\Controllers\Api\MenuItemController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PromoController;
use App\Http\Controllers\Api\SmartCategoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('employee')->group(function () {
    Route::post('login', [EmployeeAuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('check-identifier', [EmployeeAuthController::class, 'checkIdentifier'])->middleware('throttle:10,1');
});

Route::get('branches', [BranchController::class, 'index']);
Route::get('branches/by-name/{name}', [BranchController::class, 'getByName']);
Route::get('branches/{branch}', [BranchController::class, 'show']);
Route::get('branches/{branch}/menu-items', [BranchController::class, 'getMenuItemIds']);
Route::get('branches/{branch}/menu-items/{itemId}/available', [BranchController::class, 'isItemAvailable']);
Route::get('menu-categories', [MenuCategoryController::class, 'index']);
Route::get('menu-items', [MenuItemController::class, 'index']);
Route::get('menu-items/{menuItem}', [MenuItemController::class, 'show']);
Route::get('smart-categories', [SmartCategoryController::class, 'index']);
Route::get('media/{media}/{conversion?}', MediaController::class)->name('media.show');
Route::get('orders/by-number/{orderNumber}', [OrderController::class, 'showByNumber']);
Route::post('promos/resolve', [PromoController::class, 'resolve']);

// Public checkout config (service charge settings for frontend display)
Route::get('checkout-config', function () {
    $service = app(\App\Services\SystemSettingService::class);
    $enabled = $service->getBoolean('service_charge_enabled', true);

    return response()->json([
        'data' => [
            'service_charge_enabled' => $enabled,
            'service_charge_percent' => $enabled ? $service->getInteger('service_charge_percent', 1) : 0,
            'service_charge_cap' => $enabled ? $service->getInteger('service_charge_cap', 5) : 0,
            'delivery_fee_enabled' => $service->getBoolean('delivery_fee_enabled', false),
            'global_operating_hours_open' => $service->get('global_operating_hours_open', '08:00'),
            'global_operating_hours_close' => $service->get('global_operating_hours_close', '22:00'),
        ],
    ]);
});
