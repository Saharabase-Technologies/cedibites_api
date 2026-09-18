<?php

use App\Http\Controllers\Api\PromoController;
use Illuminate\Support\Facades\Route;

/*
 * Resolution is NOT here. It lives in routes/public.php, because the person it
 * answers for is usually a guest at checkout who has never signed in, and it
 * reads nothing about the caller: item ids, a branch and a subtotal go in, a
 * promo comes out.
 *
 * It used to be declared in both files. This one is required inside the
 * auth:sanctum group in api.php and public.php is required above it, so the
 * later registration replaced the earlier one and the public route was dead.
 * Every guest checkout got a 401 from it, and the frontend read that 401 as a
 * dead session and threw them back to the home page mid-order.
 *
 * Promo administration is a staff surface, and that part does belong here.
 */
Route::middleware(['token.staff', 'permission:manage_menu'])->group(function () {
    Route::get('promos', [PromoController::class, 'index']);
    Route::get('promos/{promo}', [PromoController::class, 'show']);
    Route::post('promos', [PromoController::class, 'store']);
    Route::patch('promos/{promo}', [PromoController::class, 'update']);
    Route::delete('promos/{promo}', [PromoController::class, 'destroy']);

    // One-off codes: a batch made for one promo, each code good for one order.
    Route::get('promos/{promo}/codes', [PromoController::class, 'codes']);
    Route::post('promos/{promo}/codes', [PromoController::class, 'generateCodes']);
    Route::delete('promos/{promo}/codes/{promoCode}', [PromoController::class, 'destroyCode']);
});
