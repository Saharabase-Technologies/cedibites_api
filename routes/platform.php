<?php

use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\PlatformSettingsController;
use Illuminate\Support\Facades\Route;

/**
 * Platform Admin routes — highest privilege level.
 * All routes require auth:sanctum (applied in api.php) + role:tech_admin.
 */
Route::middleware('role:tech_admin')->prefix('platform')->group(function () {
    // System health
    Route::get('health', [PlatformController::class, 'health'])->middleware('permission:view_system_health');
    Route::get('sms-health', [PlatformController::class, 'smsHealth'])->middleware('permission:view_system_health');

    // Smart error feed
    Route::get('errors', [PlatformController::class, 'errors'])->middleware('permission:view_error_logs');

    // Failed jobs
    Route::get('failed-jobs', [PlatformController::class, 'failedJobs'])->middleware('permission:view_error_logs');
    Route::post('failed-jobs/retry', [PlatformController::class, 'retryJob'])->middleware('permission:view_error_logs');

    // Password reset for staff
    Route::post('reset-password', [PlatformController::class, 'resetPassword'])->middleware('permission:reset_passwords');

    // Staff password management (passcode-gated)
    Route::post('staff-passwords', [PlatformController::class, 'staffPasswords'])->middleware('permission:reset_passwords');
    Route::post('view-password', [PlatformController::class, 'viewPassword'])->middleware('permission:reset_passwords');

    // Active sessions
    Route::get('sessions', [PlatformController::class, 'activeSessions'])->middleware('permission:view_system_health');

    // Platform admin management
    Route::get('admins', [PlatformController::class, 'listAdmins'])->middleware('permission:manage_platform');
    Route::post('admins', [PlatformController::class, 'createAdmin'])->middleware('permission:manage_platform');
    Route::delete('admins/{user}', [PlatformController::class, 'revokeAdmin'])->middleware('permission:manage_platform');

    // Create a brand-new user (any role) from the vault — passcode-gated
    Route::post('create-user', [PlatformController::class, 'createUser'])->middleware('permission:manage_platform');

    // Passcode management (self-service)
    Route::put('passcode', [PlatformController::class, 'updatePasscode']);

    // Cache management
    Route::post('cache/clear', [PlatformController::class, 'clearCache'])->middleware('permission:manage_cache');

    // Maintenance mode
    Route::post('maintenance', [PlatformController::class, 'toggleMaintenance'])->middleware('permission:toggle_maintenance');

    // Runtime settings — the toggles that replace SSHing in to edit `.env`.
    // Gated on `manage_platform`, alongside minting tech admins, because these
    // change how the platform behaves for everybody. They are DB overrides on an
    // allowlist: this cannot write `.env` and cannot reach a credential.
    Route::get('settings', [PlatformSettingsController::class, 'index'])->middleware('permission:manage_platform');
    Route::put('settings', [PlatformSettingsController::class, 'update'])->middleware('permission:manage_platform');
    Route::post('settings/revert', [PlatformSettingsController::class, 'revert'])->middleware('permission:manage_platform');
});
