<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A new sale the customer pays in parts at the counter (Phase 5, D41).
 *
 * It is placed with nothing paid and goes to the kitchen at once, like Pay
 * later. It is not Pay later: the money was never owed for a while, so the
 * cashier's copy says "Split payment", not "Paid later" (D42). Set when
 * placed, never cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('split_payment')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('split_payment');
        });
    }
};
