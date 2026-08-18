<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separates marketing traffic from the transactional pipe in the health signal.
 *
 * SmsHealthService exists to answer one question: can a customer still be told
 * their order is ready? A campaign writes one attempt row per recipient, so a
 * single blast to the contact list outnumbers a day of order notifications by
 * orders of magnitude — and one rejected batch writes thousands of failures in
 * a single moment, which would trip the systemic alert whether or not order SMS
 * is actually broken.
 *
 * Campaign rows are still recorded in full. They are simply not evidence about
 * the pipe the alerting exists to protect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_delivery_attempts', function (Blueprint $table) {
            $table->boolean('is_campaign')->default(false);

            // The health window query now filters on all three.
            $table->index(['is_campaign', 'created_at', 'succeeded']);
        });
    }

    public function down(): void
    {
        Schema::table('sms_delivery_attempts', function (Blueprint $table) {
            $table->dropIndex(['is_campaign', 'created_at', 'succeeded']);
            $table->dropColumn('is_campaign');
        });
    }
};
