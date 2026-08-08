<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every time a rule matched — including the times it deliberately sent nothing.
 *
 * A log rather than a counter, because the cooldown, the lifetime cap and the
 * response rate are all questions about history and a counter answers none of
 * them. More importantly it is the only thing that can answer "why did this
 * person not get it?", which is the question actually asked when a trigger looks
 * broken.
 *
 * SUPPRESSED FIRINGS ARE RECORDED TOO, and that is the point of the table. A
 * rule that matches four hundred orders and sends twelve is working exactly as
 * intended, and without the other three hundred and eighty-eight written down it
 * looks like a rule that barely fires. One of those readings leads to loosening
 * the cooldown; the other leads to leaving it alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_fires', function (Blueprint $table) {
            $table->id();

            $table->foreignId('automation_rule_id')->constrained()->cascadeOnDelete();

            // The order that caused it. Nulled rather than cascaded if the order
            // is ever removed — the fact that we messaged somebody is history
            // even when the order behind it is gone.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            // Normalised +233XXXXXXXXX. The cooldown is keyed on this rather than
            // on a customer id, because plenty of orders are guest checkouts with
            // no customer record and the person is the same either way.
            $table->string('phone', 20);

            $table->timestamp('fired_at');

            // Null until it actually goes out. The gap between fired_at and
            // sent_at is the delay; a row that never gets a sent_at was stopped
            // by a guard re-checked at send time.
            $table->timestamp('sent_at')->nullable();

            /*
             * Why nothing was sent, when nothing was sent.
             *
             * Null means it went. Anything else is one of the guards —
             * cooldown, lower priority, lifetime cap, not sampled, rule
             * switched off, kill switch, order cancelled in the meantime.
             */
            $table->string('suppressed_reason', 48)->nullable();

            // Set when the recipient answers, so response rate per rule is a
            // join rather than a guess.
            $table->foreignId('order_feedback_id')->nullable()->constrained('order_feedback')->nullOnDelete();

            $table->timestamps();

            // The cooldown asks "has this number heard from us lately?" on every
            // completed order, across all rules. That query has to be cheap.
            $table->index(['phone', 'fired_at']);

            // Lifetime cap, and the per-rule reporting.
            $table->index(['automation_rule_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_fires');
    }
};
