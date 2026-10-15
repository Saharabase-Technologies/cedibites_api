<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who took each payment, and on which shift (D8, D9).
 *
 * The sale belongs to whoever placed the order. The money belongs to whoever
 * took it. Until now a payment recorded neither, and a shift's total was
 * whatever the browser remembered to send after the sale (T12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('collected_by')->nullable()->constrained('employees')->nullOnDelete();
            // Set by the backfill, which can only guess from the order.
            $table->boolean('collector_inferred')->default(false);
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            // till, online or manual_entry; pay_later, split and
            // branch_code_link come with Phases 3 and 5.
            $table->string('source', 24)->nullable();

            $table->index(['collected_by', 'paid_at']);
            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['collected_by', 'paid_at']);
            $table->dropIndex(['shift_id']);
            $table->dropConstrainedForeignId('shift_id');
            $table->dropConstrainedForeignId('collected_by');
            $table->dropColumn(['collector_inferred', 'source']);
        });
    }
};
