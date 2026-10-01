<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who a campaign was meant for, and how far each of them got.
     *
     * NEVER PRUNED. This is the table a resend is built from, and it exists
     * because the only other record of who was missed is `sms_delivery_attempts`,
     * which `sms:health-check` clears after thirty days. The first real campaign
     * on production reached 39 of 3,539 and there was no way to send to the
     * other 3,500 without texting the 39 twice.
     *
     * Kept apart from `campaign_deliveries` on purpose. That table is what
     * Hubtel says happened to a message it accepted. This one is whether Hubtel
     * accepted it at all, and it has rows before a single request goes out.
     */
    public function up(): void
    {
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();

            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();

            // +233XXXXXXXXX, the way every other table holds a number.
            $table->string('phone', 20);

            // See CampaignRecipientState.
            $table->string('state', 16)->default('queued');

            // Why the last attempt did not go, as an SmsFailureReason value.
            $table->string('failure_reason', 32)->nullable();

            $table->string('batch_id', 64)->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('attempted_at')->nullable();

            $table->timestamps();

            // One row per person per campaign, which is also what stops a
            // resend from adding somebody twice.
            $table->unique(['campaign_id', 'phone']);
            $table->index(['campaign_id', 'state']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable();
            // An SmsFailureReason value. Null on a campaign that is not paused.
            $table->string('pause_reason', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['paused_at', 'pause_reason']);
        });

        Schema::dropIfExists('campaign_recipients');
    }
};
