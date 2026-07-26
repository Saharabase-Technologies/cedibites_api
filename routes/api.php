<?php

use Illuminate\Support\Facades\Route;

require __DIR__.'/auth.php';
require __DIR__.'/public.php';
require __DIR__.'/cart.php';

// Phone-as-camera upload sessions. Required OUTSIDE the auth group on purpose:
// the file declares its own middleware, because the pair of routes a phone hits
// have no logged-in user - the token in the URL is the whole credential.
require __DIR__.'/uploads.php';

Route::middleware('auth:sanctum')->group(function () {
    require __DIR__.'/protected.php';
    require __DIR__.'/employee.php';
    require __DIR__.'/manager.php';
    require __DIR__.'/admin.php';
    require __DIR__.'/promos.php';
    require __DIR__.'/platform.php';
    require __DIR__.'/feedback.php';
});

// Hubtel Payment Routes
Route::post('payments/hubtel/callback', [App\Http\Controllers\Api\PaymentController::class, 'hubtelCallback'])
    ->name('payments.hubtel.callback');

// Hubtel Direct Receive Money (RMP) callback — used for POS mobile money payments
Route::post('payments/hubtel/rmp/callback', [App\Http\Controllers\Api\PaymentController::class, 'hubtelRmpCallback'])
    ->name('payments.hubtel.rmp.callback');

Route::middleware('optional.auth')->group(function () {
    Route::post('orders/{order}/payments/hubtel/initiate', [App\Http\Controllers\Api\PaymentController::class, 'initiateHubtelPayment'])
        ->name('payments.hubtel.initiate');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('payments/{payment}/verify', [App\Http\Controllers\Api\PaymentController::class, 'verifyPayment'])
        ->name('payments.verify');
});
