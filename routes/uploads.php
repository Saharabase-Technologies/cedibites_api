<?php

use App\Http\Controllers\Api\PublicUploadController;
use App\Http\Controllers\Api\UploadSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Upload Sessions (phone-as-camera)
|--------------------------------------------------------------------------
|
| Everyone in the IMS works on a laptop, and nobody carries a laptop to a crate
| of spoiled chicken on the floor. The desktop draws a QR code, a phone scans
| it, and a no-login page attaches photos and video to exactly one document.
|
| Top-level rather than under `/inventory`, and polymorphic rather than
| wastage-shaped, because deliveries and daily counts have the same problem.
| Notably it is NOT behind the `inventory.enabled` kill switch: that flag exists
| to turn the IMS off, and this is general infrastructure that other modules are
| meant to reuse.
|
| The public pair below sit OUTSIDE auth middleware. The token in the URL is the
| whole credential and it lives in a screenshot-able square, so both routes are
| throttled per token AND per IP, and everything they can reach is upload-only.
|
*/

Route::prefix('upload-sessions')->name('upload-sessions.')->group(function () {

    // ── Public: what the phone talks to ──────────────────────────────────────
    // The pattern is a cheap first gate: `Str::random()` is alphanumeric, so
    // anything else is a probe and gets a 404 from the router without ever
    // reaching a controller, a hash, or the database.
    Route::get('{token}', [PublicUploadController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{20,64}')
        ->middleware('throttle:upload-session-view')
        ->name('public.show');

    Route::post('{token}/files', [PublicUploadController::class, 'store'])
        ->where('token', '[A-Za-z0-9]{20,64}')
        ->middleware('throttle:upload-session-store')
        ->name('public.store');

    // ── Authenticated: what the laptop talks to ──────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {
        // Minting is throttled too. A token is a credential, and a bug or a
        // stuck button should not be able to mint hundreds of them.
        Route::post('/', [UploadSessionController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('store');

        // Numeric-only, and not merely for tidiness. These sit on the same URI
        // shape as the public `{token}` routes, so an unconstrained
        // `{uploadSession}` swallows malformed tokens and answers 405 "method
        // not allowed" - which both leaks the route shape to an unauthenticated
        // caller and is the wrong answer to give a phone.
        Route::get('{uploadSession}/status', [UploadSessionController::class, 'show'])
            ->whereNumber('uploadSession')
            ->name('show');

        Route::delete('{uploadSession}', [UploadSessionController::class, 'destroy'])
            ->whereNumber('uploadSession')
            ->name('destroy');
    });
});
