<?php

namespace App\Domain\Inventory;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    /**
     * Register IMS-specific bindings.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap IMS module: load routes only when feature flag is enabled.
     */
    public function boot(): void
    {
        if (! config('features.inventory.enabled', false)) {
            return;
        }

        $this->loadInventoryRoutes();
    }

    private function loadInventoryRoutes(): void
    {
        Route::prefix('v1')
            ->middleware('api')
            ->group(base_path('routes/inventory.php'));
    }
}
