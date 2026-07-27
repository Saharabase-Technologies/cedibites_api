<?php

use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\MenuItemAvailabilityController;
use Illuminate\Support\Facades\Route;

Route::prefix('manager')->middleware(['permission:view_branches', 'branch.access'])->group(function () {
    Route::get('branches/{branch}/employees', [BranchController::class, 'employees'])
        ->middleware('permission:view_employees');
    Route::get('branches/{branch}/orders', [BranchController::class, 'orders'])
        ->middleware('permission:view_orders');
    Route::get('branches/{branch}/stats', [BranchController::class, 'stats']);
    Route::get('branches/{branch}/top-items', [BranchController::class, 'topItems']);
    Route::get('branches/{branch}/revenue-chart', [BranchController::class, 'revenueChart']);
    Route::get('branches/{branch}/staff-sales', [BranchController::class, 'staffSales']);
});

// "We've run out of Jollof today." The branch manager's only menu power — he
// takes a dish off his own branch and puts it back, and touches nothing else.
// The menu is one menu across every branch, so renaming, repricing, creating
// and deleting are the Admin's, and so are per-branch prices.
Route::prefix('manager')->middleware(['permission:menu.availability.manage', 'branch.access'])->group(function () {
    Route::get('branches/{branch}/menu-availability', [MenuItemAvailabilityController::class, 'index']);
    Route::patch('branches/{branch}/menu-availability/{menuItem}', [MenuItemAvailabilityController::class, 'update']);
});
