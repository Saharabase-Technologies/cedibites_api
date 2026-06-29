<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_purchase_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_id')->constrained('inventory_purchases')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('inventory_units')->restrictOnDelete();

            // Links back to the PO line this receipt fulfils (null for urgent buys)
            $table->foreignId('purchase_order_item_id')->nullable()->constrained('inventory_purchase_order_items')->restrictOnDelete();

            // Expected (PO line outstanding) snapshot + computed variance vs received
            $table->decimal('ordered_qty', 14, 4)->nullable();
            $table->decimal('received_qty', 14, 4);
            $table->decimal('variance', 14, 4)->nullable();
            $table->text('variance_reason')->nullable();

            $table->decimal('unit_cost_paid', 14, 4);
            $table->decimal('line_total', 14, 4)->default(0);

            $table->timestamps();

            $table->index('purchase_id');
            $table->index('item_id');
            $table->index('purchase_order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_purchase_items');
    }
};
