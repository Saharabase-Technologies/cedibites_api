<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalized O(1) balance cache. One row per (item, location).
 * Maintained under row lock by MovementPostingEngine on every ledger write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stock_balances', function (Blueprint $table) {
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();

            $table->decimal('quantity', 14, 4)->default(0);
            $table->decimal('weighted_avg_cost', 14, 4)->default(0);
            $table->unsignedBigInteger('last_movement_id')->nullable();

            $table->timestamp('updated_at')->nullable();

            $table->primary(['item_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_balances');
    }
};
