<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened to each recipient of a campaign.
 *
 * Its own table rather than a reuse of `sms_delivery_attempts`, which already
 * carries a campaign_id and looks like the obvious home. That table is PRUNED by
 * sms:health-check — it exists to measure a failure rate over a recent window,
 * and keeping it small is the point. Reporting campaign delivery from it would
 * mean the answer to "how many arrived?" quietly changed month to month and
 * eventually became zero, which is the same trap the campaign totals were kept
 * off it to avoid.
 *
 * This table is never pruned. It is the only durable record of who received
 * what, and it is what makes "the ones that were not delivered" a list you can
 * act on rather than a number you can only look at.
 *
 * One row per recipient per campaign, upserted as the poller learns more. The
 * provider's own wording is kept beside our classification so a status nobody
 * anticipated is preserved rather than flattened into a guess — see
 * App\Enums\DeliveryOutcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_deliveries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();

            // Normalised +233XXXXXXXXX, matching how contacts and customers hold
            // it, so a failed number can be looked up against the contact base
            // without reformatting anything.
            $table->string('phone', 20);

            // Ours: delivered / failed / pending / unconfirmed.
            $table->string('outcome', 24)->default('pending');

            // Hubtel's, verbatim. Never parsed for reporting — kept so a wording
            // change shows up as an unclassified status rather than as a silent
            // shift in the delivered count.
            $table->string('provider_status', 64)->nullable();

            // What this one message was actually charged, when the provider says.
            $table->decimal('rate', 8, 4)->nullable();

            $table->timestamps();

            // One row per person per campaign; the poller upserts on this.
            $table->unique(['campaign_id', 'phone']);

            // The breakdown filters by outcome constantly.
            $table->index(['campaign_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_deliveries');
    }
};
