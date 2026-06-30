<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Line items on a transfer. `requested_qty` is set at draft; `sent_qty` at send
 * (FEFO-allocated from the source — the allocation snapshot is kept so the
 * destination batch inherits source cost + expiry); `received_qty` at receipt.
 * A received_qty < sent_qty is the shortfall that drives a dispute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('inventory_transfers')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('inventory_units')->restrictOnDelete();
            $table->decimal('requested_qty', 14, 4);
            $table->decimal('sent_qty', 14, 4)->nullable();
            $table->decimal('received_qty', 14, 4)->nullable();
            $table->decimal('unit_cost_at_time', 14, 4)->nullable();
            // FEFO allocation snapshot captured at send: [{batch_id, qty, unit_cost, expiry_date}].
            $table->json('sent_allocations')->nullable();
            $table->timestamps();

            $table->index('transfer_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_lines');
    }
};
