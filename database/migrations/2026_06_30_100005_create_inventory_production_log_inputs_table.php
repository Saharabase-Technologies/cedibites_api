<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One consumed-input line of a production run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_production_log_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_log_id')->constrained('inventory_production_logs')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('inventory_units')->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost_at_time', 14, 4)->default(0);
            $table->decimal('line_cost', 14, 4)->default(0);
            $table->timestamps();

            $table->index('production_log_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_production_log_inputs');
    }
};
