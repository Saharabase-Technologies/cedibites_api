<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The name the payer's MoMo number is registered to, shown beside the payment
 * as a check on who paid (D12, D13).
 *
 * Beta already has this column. The parked USSD service work
 * (feat/hubtel-ussd-service, 2026_10_13_111553) added it there on 2026-10-13,
 * and that migration never goes to prod (D34). So this one adds the column
 * only where it is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('hubtel_incoming_payments', 'payer_name')) {
            return;
        }

        Schema::table('hubtel_incoming_payments', function (Blueprint $table) {
            $table->string('payer_name', 120)->nullable()->after('payer_number');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('hubtel_incoming_payments', 'payer_name')) {
            return;
        }

        Schema::table('hubtel_incoming_payments', function (Blueprint $table) {
            $table->dropColumn('payer_name');
        });
    }
};
