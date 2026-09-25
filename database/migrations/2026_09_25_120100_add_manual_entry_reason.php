<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a sale was written on paper and entered afterwards.
 *
 * A manual entry is the record of trading the system did not see: the power
 * was off, the network was down, the till would not start. The reason is what
 * makes it readable at the end of the month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->text('manual_entry_reason')->nullable()->after('recorded_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->text('manual_entry_reason')->nullable()->after('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('manual_entry_reason');
        });

        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->dropColumn('manual_entry_reason');
        });
    }
};
