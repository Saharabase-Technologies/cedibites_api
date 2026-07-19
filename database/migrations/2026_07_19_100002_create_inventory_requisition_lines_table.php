<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Line items on a requisition. `requested_qty` is what the branch asked for;
 * `approved_qty` is what the warehouse manager granted at approval (defaults to
 * the requested amount, can be trimmed). The fulfilling transfer is built from
 * the approved quantities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_requisition_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained('inventory_requisitions')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('inventory_units')->restrictOnDelete();
            $table->decimal('requested_qty', 14, 4);
            $table->decimal('approved_qty', 14, 4)->nullable();
            $table->timestamps();

            $table->index('requisition_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_requisition_lines');
    }
};
