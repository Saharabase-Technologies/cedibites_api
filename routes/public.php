<?php

use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\EmployeeAuthController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MenuCategoryController;
use App\Http\Controllers\Api\MenuItemController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PromoController;
use App\Http\Controllers\Api\RecruitmentController;
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
// Guest order tracking. Deliberately unauthenticated: this is where the payment
// gateway redirects a guest after they pay, and they have no account to log into.
//
// Order numbers are sequential and guessable (A001, A002 … B001 — see
// OrderNumberService), so without a limit anyone could walk the whole order
// history and read off each branch's daily volume. The response carries no
// customer name, phone or address, so the exposure is what was ordered rather
// than who ordered it — but the enumeration itself is the problem, and throttling
// takes it from trivial to impractical.
//
// A per-order tracking token in the URL would close this properly. That is a
// contract change across checkout, the receipt and the gateway redirect, so it is
// tracked in docs/BRANCH_ISOLATION_PLAN.md rather than done here.
Route::get('orders/by-number/{orderNumber}', [OrderController::class, 'showByNumber'])
    ->middleware('throttle:20,1');
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

/*
|--------------------------------------------------------------------------
| Recruitment
|--------------------------------------------------------------------------
|
| The form a recruit fills in. Unauthenticated: the token in the URL is the
| only credential, and whoever holds it can apply. That is acceptable because
| nothing here creates an account — a submission is a row waiting for a
| reviewer. It is also why every link carries an expiry.
|
| Throttled harder than the rest of the public surface. By decision there is no
| headcount cap and no close button, so the rate limit and the expiry date are
| the only things standing between a forwarded link and a flooded review queue.
*/
Route::get('recruit/{token}', [RecruitmentController::class, 'show'])
    ->middleware('throttle:20,1');
Route::post('recruit/{token}', [RecruitmentController::class, 'store'])
    ->middleware('throttle:5,1');
