<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a receipt has ever been produced for this order.
 *
 * The till knows it printed one, but only for the sale it just rang up and only
 * on that device. Nothing recorded it against the order, so a screen opened
 * anywhere else could not tell a receipt that had already been handed over from
 * one that had never been printed at all — which is the difference between
 * "Print receipt" and "Reprint receipt", and the difference between a customer
 * who has their slip and one still waiting for it.
 *
 * A count as well as a timestamp: repeated reprints on the same order are worth
 * being able to see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('receipt_printed_at')->nullable()->after('recorded_at');
            $table->unsignedInteger('receipt_print_count')->default(0)->after('receipt_printed_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['receipt_printed_at', 'receipt_print_count']);
        });
    }
};
