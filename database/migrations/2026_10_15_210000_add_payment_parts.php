<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments taken on an order that owes, in parts (D7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Our own reference for each MoMo prompt sent for a part, so
            // Hubtel's callback finds the right part (T5). PL-AJ700-2.
            $table->string('reference', 32)->nullable()->unique();
            // Cash handed over, when more than the part it paid. The rest
            // was change.
            $table->decimal('amount_tendered', 10, 2)->nullable();
        });

        Schema::table('hubtel_incoming_payments', function (Blueprint $table) {
            // The payment row a branch code payment became when a cashier
            // linked it to an order. One incoming payment, one payment row.
            $table->foreignId('payment_id')->nullable()->unique()->constrained('payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hubtel_incoming_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn(['reference', 'amount_tendered']);
        });
    }
};
