<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inventory (IMS) Routes
|--------------------------------------------------------------------------
|
| All routes are gated by the `inventory.enabled` middleware which returns
| 404 when the feature flag is off. Inside the group, routes use Sanctum
| auth + permission middleware. Phase 0: scaffold only — endpoints will be
| populated as catalog CRUD is built.
|
*/

Route::middleware(['auth:sanctum', 'inventory.enabled'])
    ->prefix('inventory')
    ->name('inventory.')
    ->group(function () {
        // Phase 0 endpoints land here:
        // - locations CRUD
        // - categories CRUD
        // - units CRUD
        // - suppliers CRUD
        // - items CRUD
    });
