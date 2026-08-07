<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Hubtel says happened, as opposed to what it accepted.
 *
 * `sent_count` has always meant "Hubtel took this message from us". That is not
 * the same as it arriving, and until now nothing recorded the difference —
 * a campaign to 4,000 people showed 4,000 sent whether 4,000 or 40 handsets ever
 * lit up.
 *
 * `GET /v1/messages/batch/{batchId}` answers both questions at once: a per-message
 * `status` and, finally, a real `rate`. Found by probing on 2026-08-07 after the
 * send response turned out to carry no rate at all and the per-message lookup
 * 404'd — the message ids a send returns are not the ones the query accepts.
 *
 * The batch ids live here rather than on sms_delivery_attempts because that table
 * is pruned by sms:health-check, and a campaign whose batch ids had been trimmed
 * could never have its cost resolved. A 28,000-recipient campaign stores 28 of
 * them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // One per chunk. What the delivery poll needs to ask about.
            $table->json('batch_ids')->nullable()->after('failed_count');

            // Confirmed as arriving, which is a smaller number than sent_count
            // and the more honest one to put in front of anybody.
            $table->unsignedInteger('delivered_count')->default(0)->after('failed_count');

            $table->timestamp('delivery_checked_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['batch_ids', 'delivered_count', 'delivery_checked_at']);
        });
    }
};
