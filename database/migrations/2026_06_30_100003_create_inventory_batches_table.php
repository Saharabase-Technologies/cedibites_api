<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expiry/FEFO batches. One row per received lot of an expiry-tracked item at a
 * location. Stock-out movements consume the soonest-expiring batch first
 * (First-Expiry-First-Out), decrementing remaining_qty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('purchase_item_id')->nullable()->constrained('inventory_purchase_items')->nullOnDelete();

            $table->decimal('received_qty', 14, 4);
            $table->decimal('remaining_qty', 14, 4);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->date('expiry_date')->nullable();
            $table->timestamp('received_at');

            $table->timestamps();

            // FEFO scan: open batches for an item+location, soonest expiry first.
            $table->index(['item_id', 'location_id', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_batches');
    }
};
