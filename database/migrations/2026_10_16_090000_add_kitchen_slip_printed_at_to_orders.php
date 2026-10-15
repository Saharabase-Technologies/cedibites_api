<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a Pay later order's kitchen slip was first printed (D5).
 *
 * The slip that says FOR THE KITCHEN, NOT PAID is not the customer's receipt,
 * so it never touches receipt_print_count: the paid receipt that follows is
 * still the original (D6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('kitchen_slip_printed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('kitchen_slip_printed_at');
        });
    }
};
