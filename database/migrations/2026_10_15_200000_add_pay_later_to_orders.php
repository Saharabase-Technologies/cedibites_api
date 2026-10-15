<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pay later (D1 to D7). An order is placed with nothing paid, goes to the
 * kitchen at once, and is paid in any number of parts afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Set when placed with Pay later. Never cleared: it is the mark an
            // investigation looks for (D2).
            $table->boolean('paid_later')->default(false);
            $table->timestamp('placed_unpaid_at')->nullable();
            // When the payments first covered the total.
            $table->timestamp('fully_paid_at')->nullable();
            // When the one-hour alert went (D10), so it goes once.
            $table->timestamp('unpaid_alerted_at')->nullable();

            $table->index(['paid_later', 'fully_paid_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['paid_later', 'fully_paid_at']);
            $table->dropColumn(['paid_later', 'placed_unpaid_at', 'fully_paid_at', 'unpaid_alerted_at']);
        });
    }
};
