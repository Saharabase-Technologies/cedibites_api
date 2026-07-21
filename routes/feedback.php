<?php

use App\Http\Controllers\Api\Feedback\FeedbackReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Feedback Routes
|--------------------------------------------------------------------------
|
| Required inside the auth:sanctum group in api.php, so every route here is
| authenticated. Submitting a report and viewing one's own reports need no
| further permission; triage routes are gated by `feedback.triage`. The system
| is never feature-flagged off at the API — endpoints stay live for support use.
|
*/

Route::prefix('feedback')->name('feedback.')->group(function () {
    // Any authenticated user.
    Route::post('reports', [FeedbackReportController::class, 'store'])->name('reports.store');
    Route::get('my-reports', [FeedbackReportController::class, 'myReports'])->name('my-reports');

    // Triage — admin / tech-admin (inherit feedback.triage via the seeder).
    Route::middleware('permission:feedback.triage')->group(function () {
        Route::get('reports', [FeedbackReportController::class, 'index'])->name('reports.index');
        Route::get('reports/{feedbackReport}', [FeedbackReportController::class, 'show'])->name('reports.show');
        Route::patch('reports/{feedbackReport}', [FeedbackReportController::class, 'update'])->name('reports.update');
        Route::get('reports/{feedbackReport}/logs', [FeedbackReportController::class, 'logs'])->name('reports.logs');
        Route::post('reports/{feedbackReport}/transcribe', [FeedbackReportController::class, 'transcribe'])->name('reports.transcribe');
        Route::get('reports/{feedbackReport}/export', [FeedbackReportController::class, 'export'])->name('reports.export');
    });
});
