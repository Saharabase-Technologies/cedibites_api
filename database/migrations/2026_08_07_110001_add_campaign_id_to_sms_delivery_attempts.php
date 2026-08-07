<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which campaign an attempt belongs to.
 *
 * Per-recipient detail, and deliberately prunable — `sms:health-check` trims
 * this table, and that is fine here because nothing in campaign *reporting*
 * reads it. The permanent counts live on the campaign row.
 *
 * What this column is for is the delivery-status poll, which runs within hours
 * of a send and needs to know which message ids belong to which campaign.
 *
 * nullOnDelete rather than cascade: deleting a campaign should not silently
 * rewrite the SMS health record for the window it ran in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_delivery_attempts', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('is_campaign')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sms_delivery_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });
    }
};
