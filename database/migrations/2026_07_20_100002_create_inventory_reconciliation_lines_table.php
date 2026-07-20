<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One counted item on a reconciliation cycle. `system_qty` is the ledger balance
 * snapshotted at open; `counted_qty` is the physical count; `variance` = counted
 * − system; `variance_value` = variance × unit_cost. On posting, a cycle_adjustment
 * movement is written for every non-zero variance and referenced here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('inventory_reconciliation_cycles')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('inventory_units')->restrictOnDelete();
            $table->decimal('system_qty', 14, 4);
            $table->decimal('counted_qty', 14, 4)->nullable();
            $table->decimal('variance', 14, 4)->nullable();
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('variance_value', 16, 4)->nullable();
            $table->boolean('over_threshold')->default(false);
            // The cycle_adjustment movement posted for this line (null if no variance).
            $table->unsignedBigInteger('adjustment_movement_id')->nullable();
            $table->timestamps();

            $table->unique(['cycle_id', 'item_id']);
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reconciliation_lines');
    }
};
