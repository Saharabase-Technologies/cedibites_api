<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('employee')->middleware('throttle:5,1')->group(function () {
    Route::post('forgot-password', [EmployeeAuthController::class, 'forgotPassword']);
    Route::post('reset-password', [EmployeeAuthController::class, 'resetPassword']);
});

Route::prefix('auth')->group(function () {
    Route::post('send-otp', [AuthController::class, 'sendOTP'])->middleware('throttle:otp-send');
    Route::post('verify-otp', [AuthController::class, 'verifyOTP'])->middleware('throttle:otp-verify');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('quick-register', [AuthController::class, 'quickRegister'])->middleware('throttle:5,1');

    /**
     * One array, not two chained calls.
     *
     * This read `->middleware('auth:sanctum')->middleware('customer.active')`,
     * and `RouteRegistrar::attribute()` **assigns** the middleware attribute
     * rather than merging it — the merge branch in that method exists only for
     * `withoutMiddleware`. So the second call silently threw the first away and
     * these three routes ran with no authentication at all.
     *
     * `EnsureCustomerActive` returns early on a null user by design, so nothing
     * refused the request; it reached the controller, `$request->user()` was
     * null, and the reader got "Attempt to read property id on null" from a
     * form request and "Call to a member function load() on null" from
     * `user()`. Editing a name or an email has never worked.
     *
     * `php artisan route:list --path=v1/auth/user -v` is what proves it: the
     * fixed route lists `Authenticate:sanctum`, the broken one did not.
     */
    Route::middleware(['auth:sanctum', 'customer.active'])->group(function () {
        Route::get('user', [AuthController::class, 'user']);
        Route::patch('profile', [AuthController::class, 'updateProfile']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});
