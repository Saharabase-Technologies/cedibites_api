<?php

use App\Http\Controllers\Api\PromoController;
use Illuminate\Support\Facades\Route;

// Promo resolution — accessible to any authenticated user (POS staff + customers)
Route::post('promos/resolve', [PromoController::class, 'resolve']);

// Promo administration is a staff surface, unlike resolution above.
Route::middleware(['token.staff', 'permission:manage_menu'])->group(function () {
    Route::get('promos', [PromoController::class, 'index']);
    Route::get('promos/{promo}', [PromoController::class, 'show']);
    Route::post('promos', [PromoController::class, 'store']);
    Route::patch('promos/{promo}', [PromoController::class, 'update']);
    Route::delete('promos/{promo}', [PromoController::class, 'destroy']);
});
