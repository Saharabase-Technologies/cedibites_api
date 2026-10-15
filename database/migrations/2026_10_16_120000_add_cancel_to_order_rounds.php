<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A round is cancelled on its own (D40, D45).
 *
 * Asked for by staff, approved or rejected by an admin, as an order's cancel
 * is (D39). While it is asked, the round keeps its kitchen status and the
 * boards show it as asked. Once approved its status is `cancelled`; the round
 * and its items stay on record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_rounds', function (Blueprint $table) {
            $table->foreignId('cancel_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_request_reason', 500)->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancelled_reason', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_rounds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancel_requested_by');
            $table->dropColumn(['cancel_request_reason', 'cancel_requested_at']);
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_reason', 'cancelled_at']);
        });
    }
};
