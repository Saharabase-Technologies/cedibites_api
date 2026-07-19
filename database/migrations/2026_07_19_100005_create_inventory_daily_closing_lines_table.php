<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One counted item on a daily closing. `expected_qty` is the ledger balance
 * snapshotted when the count was opened; `counted_qty` is what the operator
 * physically counted; `variance` = counted − expected (null until counted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_daily_closing_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_closing_id')->constrained('inventory_daily_closings')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('inventory_units')->restrictOnDelete();
            $table->decimal('expected_qty', 14, 4);
            $table->decimal('counted_qty', 14, 4)->nullable();
            $table->decimal('variance', 14, 4)->nullable();
            $table->timestamps();

            $table->unique(['daily_closing_id', 'item_id']);
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_daily_closing_lines');
    }
};
