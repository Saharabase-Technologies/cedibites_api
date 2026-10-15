<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adding items to an order (Phase 4b, D29).
 *
 * Each addition is a round inside the same order: one order number, one
 * total, many payments. The order's first items are round 1 and have no row
 * here. A round has its own kitchen status, so it reaches the kitchen as its
 * own ticket, and its own stock deduction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            // 2 for the first addition. Round 1 is the order as placed.
            $table->unsignedSmallInteger('number');
            $table->foreignId('added_by')->nullable()->constrained('employees')->nullOnDelete();
            // The kitchen's status for this round alone.
            $table->string('status', 32)->default('received');
            $table->boolean('paid_later')->default(false);
            $table->decimal('subtotal', 10, 2);
            $table->decimal('total', 10, 2);
            $table->foreignId('checkout_session_id')->nullable()->constrained('checkout_sessions')->nullOnDelete();
            // When a customer's receipt that lists this round was first
            // printed. Null means the next receipt is an Updated Receipt.
            $table->timestamp('receipt_printed_at')->nullable();
            // When this round's kitchen slip was first printed, on an order
            // that owes (D5).
            $table->timestamp('kitchen_slip_printed_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'number']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('order_round_id')->nullable()->constrained('order_rounds')->cascadeOnDelete();
        });

        // The round a payment was taken with, when it was paid as it was added.
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('order_round_id')->nullable()->constrained('order_rounds')->nullOnDelete();
        });

        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->foreignId('add_to_order_id')->nullable()->constrained('orders')->nullOnDelete();
        });

        // When the order last started owing: placed with Pay later, or a
        // round added to a paid order. Cleared when paid. The one-hour alert
        // counts from here (D10, D32).
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('owing_since')->nullable();
        });

        DB::table('orders')
            ->where('paid_later', true)
            ->whereNull('fully_paid_at')
            ->where('status', '!=', 'cancelled')
            ->update(['owing_since' => DB::raw('placed_unpaid_at')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('owing_since');
        });

        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('add_to_order_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_round_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_round_id');
        });

        Schema::dropIfExists('order_rounds');
    }
};
