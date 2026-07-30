<?php

use App\Http\Controllers\Api\CheckoutSessionController;
use App\Http\Controllers\Api\EmployeeAuthController;
use App\Http\Controllers\Api\EmployeeOrderController;
use App\Http\Controllers\Api\PosOrderController;
use App\Http\Controllers\Api\ShiftController;
use App\Services\SystemSettingService;
use Illuminate\Support\Facades\Route;

Route::prefix('employee')->group(function () {
    Route::get('me', [EmployeeAuthController::class, 'me']);
    Route::post('logout', [EmployeeAuthController::class, 'logout']);
    Route::post('change-password', [EmployeeAuthController::class, 'changePassword']);
});

// All remaining employee routes require password reset to be cleared
Route::middleware('password.reset')->group(function () {
    // Staff order entry. The `pos` prefix is historical — this is the path every
    // staff-placed order takes, whether it was rung up at a till or taken over
    // the phone by the call centre. Gated on `create_orders`, the permission that
    // actually means "may place an order", rather than `access_pos`, which is
    // about reaching the terminal UI. The call centre places orders and never
    // touches a till, so gating the two on the same permission kept them out of
    // the only endpoint that can record their work.
    Route::prefix('pos')->middleware('permission:create_orders')->group(function () {
        Route::post('orders', [PosOrderController::class, 'store']);
        Route::post('verify-momo', [PosOrderController::class, 'verifyMomo']);

        // No stock, no sale — advisory reads so the till can grey an item out
        // as the cart is built. The rule itself lives in PosOrderController.
        Route::get('stock-gate', [\App\Http\Controllers\Api\StockGateController::class, 'index']);
        Route::post('stock-gate/check', [\App\Http\Controllers\Api\StockGateController::class, 'check']);

        // Checkout sessions (POS)
        Route::post('checkout-sessions', [CheckoutSessionController::class, 'posStore'])
            ->middleware('throttle:30,1');
        Route::get('checkout-sessions', [CheckoutSessionController::class, 'posIndex']);
        Route::get('checkout-sessions/{token}', [CheckoutSessionController::class, 'show']);
        Route::post('checkout-sessions/{token}/confirm-cash', [CheckoutSessionController::class, 'confirmCash']);
        Route::post('checkout-sessions/{token}/confirm-card', [CheckoutSessionController::class, 'confirmCard']);
        Route::post('checkout-sessions/{token}/retry-payment', [CheckoutSessionController::class, 'retryPayment']);
        Route::post('checkout-sessions/{token}/change-payment', [CheckoutSessionController::class, 'changePayment']);
        Route::post('checkout-sessions/{token}/cancel', [CheckoutSessionController::class, 'cancel']);
        Route::delete('checkout-sessions/{token}', [CheckoutSessionController::class, 'destroy']);
    });

    Route::prefix('shifts')->middleware('permission:view_my_shifts')->group(function () {
        Route::get('/', [ShiftController::class, 'index']);
        Route::get('active/{employeeId}', [ShiftController::class, 'getActive']);
        Route::get('by-date/{date}', [ShiftController::class, 'getByDate']);
        Route::get('by-staff/{staffId}', [ShiftController::class, 'getByStaff']);

        Route::middleware('permission:manage_shifts')->group(function () {
            Route::post('/', [ShiftController::class, 'startShift']);
            Route::patch('{shift}/end', [ShiftController::class, 'endShift']);
            Route::post('{shift}/orders', [ShiftController::class, 'addOrder']);
        });
    });

    Route::prefix('employee')->middleware('permission:view_orders')->group(function () {
        Route::get('orders', [EmployeeOrderController::class, 'index']);
        Route::get('orders/stats', [EmployeeOrderController::class, 'stats']);
        Route::get('orders/summary', [EmployeeOrderController::class, 'summary']);
        Route::get('orders/pending', [EmployeeOrderController::class, 'pending']);
        Route::patch('orders/{order}/status', [EmployeeOrderController::class, 'updateStatus'])
            ->middleware('permission:update_orders');
        // Asking for a cancellation is not the same power as moving an order
        // through the kitchen, and the call centre needs exactly one of the two.
        // See Permission::OrderCancelRequest.
        Route::post('orders/{order}/request-cancel', [\App\Http\Controllers\Api\CancelRequestController::class, 'requestCancel'])
            ->middleware('permission:order.cancel.request');
    });
});

// Read-only system settings for staff
Route::get('settings/{key}', function (string $key) {
    $allowed = ['manual_entry_date_enabled', 'service_charge_percent', 'service_charge_enabled', 'service_charge_cap', 'delivery_fee_enabled', 'global_operating_hours_open', 'global_operating_hours_close'];
    if (! in_array($key, $allowed, true)) {
        abort(404);
    }
    $service = app(SystemSettingService::class);

    return response()->json(['data' => ['key' => $key, 'value' => $service->get($key)]]);
});
