<?php

use App\Http\Controllers\Api\StaffMessaging\StaffInboxController;
use App\Http\Controllers\Api\StaffMessaging\StaffMessageController;
use App\Http\Controllers\Api\StaffMessaging\StaffMessageRuleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Staff messaging
|--------------------------------------------------------------------------
|
| Required inside the ['token.staff', 'staff.active'] group in api.php, so
| everything here already requires a token minted by the staff password login
| from somebody whose employment is still active.
|
| The inbox carries NO permission. Receiving and replying to a message is not a
| privilege — it is the job. Gating it would mean editing all ten roles, and
| whichever one was missed would silently never be able to read anything.
|
*/

Route::prefix('messages')->group(function () {
    // ─── The staff member's own inbox ────────────────────────────────────────
    Route::get('inbox', [StaffInboxController::class, 'index']);
    Route::get('inbox/summary', [StaffInboxController::class, 'summary']);
    Route::get('inbox/{recipient}', [StaffInboxController::class, 'show']);
    Route::post('inbox/{recipient}/acknowledge', [StaffInboxController::class, 'acknowledge']);
    Route::post('inbox/{recipient}/reply', [StaffInboxController::class, 'reply']);

    // ─── Upward: raising something with the IT team ──────────────────────────
    Route::post('raise', [StaffInboxController::class, 'raise']);
    Route::get('raised', [StaffInboxController::class, 'raised']);
});

/*
| Everything below sends TO staff, or governs what sends automatically. Admin
| and tech_admin only — branch managers deliberately do not send.
*/
Route::prefix('admin/messages')
    ->middleware('permission:staff_messages.manage')
    ->group(function () {
        // Rules first. `rules/options` would otherwise be swallowed by the
        // `{staffMessage}` wildcard on the message routes below.
        Route::get('rules/options', [StaffMessageRuleController::class, 'options']);
        Route::get('rules', [StaffMessageRuleController::class, 'index']);
        Route::post('rules', [StaffMessageRuleController::class, 'store']);
        Route::get('rules/{rule}', [StaffMessageRuleController::class, 'show']);
        Route::put('rules/{rule}', [StaffMessageRuleController::class, 'update']);
        Route::delete('rules/{rule}', [StaffMessageRuleController::class, 'destroy']);
        Route::post('rules/{rule}/toggle', [StaffMessageRuleController::class, 'toggle']);
        Route::get('rules/{rule}/dry-run', [StaffMessageRuleController::class, 'dryRun']);

        // Audience size before sending — the last chance to notice that the
        // selection is the whole company.
        Route::post('preview', [StaffMessageController::class, 'preview']);

        Route::get('/', [StaffMessageController::class, 'index']);
        Route::post('/', [StaffMessageController::class, 'store']);
        Route::get('{staffMessage}', [StaffMessageController::class, 'show'])->name('admin.messages.show');
        Route::post('{staffMessage}/reply', [StaffMessageController::class, 'reply']);
        Route::delete('{staffMessage}', [StaffMessageController::class, 'destroy']);
    });
