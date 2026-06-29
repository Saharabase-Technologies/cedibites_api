<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only ledger. Movements are never edited or soft-deleted.
 * Reversals are new rows with opposite sign + parent_movement_id set.
 * Balance is maintained out-of-band in inventory_stock_balances.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stock_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();

            // Signed: +receipts, -issues
            $table->decimal('quantity', 14, 4);

            $table->enum('movement_type', [
                'purchase',
                'transfer_in',
                'transfer_out',
                'sale',
                'wastage',
                'count_adjustment',
                'cycle_adjustment',
                'production',
                'return',
                'reversal',
                'opening_balance',
            ]);

            // Polymorphic source pointer (e.g. purchase / transfer line)
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            // FEFO/expiry batch — table lands in Phase 3; column present now, no FK yet
            $table->unsignedBigInteger('batch_id')->nullable();

            $table->decimal('unit_cost_at_time', 14, 4)->nullable();

            // Null for system-generated movements (e.g. order-completion deductions)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedBigInteger('parent_movement_id')->nullable();
            $table->string('idempotency_key')->unique();

            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['location_id', 'occurred_at']);
            $table->index(['item_id', 'occurred_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('parent_movement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_movements');
    }
};
