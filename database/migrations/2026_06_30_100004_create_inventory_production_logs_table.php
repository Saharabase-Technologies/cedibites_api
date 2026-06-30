<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mother-kitchen production runs: a batch that consumes raw inputs and yields a
 * prepared (semi-finished) output item, costed by the inputs. Inputs live in
 * inventory_production_log_inputs; both sides post `production` movements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_production_logs', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('output_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('output_unit_id')->constrained('inventory_units')->restrictOnDelete();
            $table->decimal('output_qty', 14, 4);
            $table->foreignId('output_batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->decimal('input_cost_total', 14, 4)->default(0);
            $table->decimal('output_unit_cost', 14, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('produced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('produced_at');
            $table->timestamps();

            $table->index(['location_id', 'produced_at']);
            $table->index('output_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_production_logs');
    }
};
