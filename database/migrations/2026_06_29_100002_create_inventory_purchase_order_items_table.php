<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_purchase_order_items', function (Blueprint $table) {
            $table->id();

            // Owned child — removed with its parent PO (PO itself is never hard-deleted in practice)
            $table->foreignId('purchase_order_id')->constrained('inventory_purchase_orders')->cascadeOnDelete();

            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('inventory_units')->restrictOnDelete();

            $table->decimal('ordered_qty', 14, 4);
            $table->decimal('received_qty', 14, 4)->default(0);
            $table->decimal('estimated_unit_cost', 14, 4);
            $table->decimal('line_total', 14, 4)->default(0);

            $table->timestamps();

            $table->index('purchase_order_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_purchase_order_items');
    }
};
