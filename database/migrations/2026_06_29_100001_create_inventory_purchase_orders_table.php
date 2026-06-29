<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();

            $table->foreignId('supplier_id')->constrained('inventory_suppliers')->restrictOnDelete();
            $table->foreignId('destination_location_id')->constrained('inventory_locations')->restrictOnDelete();

            $table->enum('status', [
                'draft',
                'pending_approval',
                'sent',
                'partially_received',
                'received',
                'closed',
                'cancelled',
            ])->default('draft')->index();

            $table->boolean('requires_approval')->default(false);

            $table->decimal('estimated_total', 14, 4)->default(0);
            $table->decimal('actual_total', 14, 4)->default(0);

            $table->date('expected_delivery_date')->nullable();
            $table->text('notes')->nullable();

            // Lifecycle actors (FK out to users, restrict on delete)
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();

            $table->timestamps();

            $table->index(['supplier_id', 'status']);
            $table->index(['destination_location_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_purchase_orders');
    }
};
