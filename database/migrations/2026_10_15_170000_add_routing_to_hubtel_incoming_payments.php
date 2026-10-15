<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which branch's till a branch code payment was sent to, and why (D26, D35).
 *
 * Hubtel's post names no branch, so branch_id is now set by our own rules,
 * or by the cashier who takes the payment from the shared list, and never
 * from the address Hubtel posted to. A null branch_id is the shared list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubtel_incoming_payments', function (Blueprint $table) {
            // number_amount, amount, history or cashier. Null while shared.
            $table->string('routed_by', 16)->nullable();
            $table->timestamp('routed_at')->nullable();
            // The order the rule matched, for number_amount and amount.
            $table->foreignId('matched_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('taken_by')->nullable()->constrained('employees')->nullOnDelete();
            // A cashier said it was not theirs. No rule places it again.
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hubtel_incoming_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn('returned_at');
            $table->dropConstrainedForeignId('taken_by');
            $table->dropConstrainedForeignId('matched_order_id');
            $table->dropColumn(['routed_by', 'routed_at']);
        });
    }
};
