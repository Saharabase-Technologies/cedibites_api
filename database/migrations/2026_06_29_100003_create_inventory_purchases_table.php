<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_purchases', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();

            // Null for urgent / market buys with no PO
            $table->foreignId('purchase_order_id')->nullable()->constrained('inventory_purchase_orders')->restrictOnDelete();

            $table->foreignId('supplier_id')->constrained('inventory_suppliers')->restrictOnDelete();
            // Free-text vendor for urgent/market buys (when supplier is the generic "Market" record)
            $table->string('supplier_name')->nullable();

            $table->foreignId('destination_location_id')->constrained('inventory_locations')->restrictOnDelete();

            $table->boolean('is_urgent_buy')->default(false);
            $table->text('urgent_buy_reason')->nullable();
            $table->string('invoice_number')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();

            $table->decimal('total_paid', 14, 4)->default(0);
            $table->timestamp('received_at');

            $table->timestamps();

            $table->index('purchase_order_id');
            $table->index('supplier_id');
            $table->index(['is_urgent_buy', 'received_at']);
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_purchases');
    }
};
