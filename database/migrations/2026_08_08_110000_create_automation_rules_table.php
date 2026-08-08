<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A message that waits for something to happen instead of for somebody to press
 * send.
 *
 * Built as an automation rule rather than a "feedback rule" on purpose. The same
 * machinery that asks a first-time customer how it went also wins back somebody
 * who went quiet and thanks somebody on their tenth order; naming it after its
 * first use would mean building it twice.
 *
 * Everything here is inert until BOTH switches are on — the global kill switch
 * in config, and `is_active` on the row. Two switches because they answer
 * different questions: "is this feature live at all?" and "is this particular
 * rule live?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            // One of App\Enums\AutomationEvent. The "when".
            $table->string('event', 48);

            // Settings the event itself needs — which N for an Nth order, how
            // long a gap counts as a gap. Separate from the audience conditions
            // because they are answers to different questions and mixing them
            // makes both harder to read.
            $table->json('event_config')->nullable();

            // App\Services\Campaigns\AudienceRules, reused wholesale. The "only
            // if". The same language the campaign builder speaks, so an operator
            // learns it once.
            $table->json('audience_rules')->nullable();

            $table->text('message');
            $table->foreignId('short_link_id')->nullable()->constrained()->nullOnDelete();

            /*
             * How long after the event to send.
             *
             * Per-rule rather than global because three hours is a guess, not a
             * finding — the right delay for "how was your first order?" is
             * probably not the right delay for "we have not seen you in a while".
             */
            $table->unsignedInteger('delay_minutes')->default(180);

            // Off. Nothing a rule can do until somebody turns it on deliberately.
            $table->boolean('is_active')->default(false);

            /*
             * Lower wins when several rules match one order.
             *
             * A first delivery, of a new dish, at a new branch is three matches
             * on a single order. Without an order of precedence that is three
             * texts in one afternoon.
             */
            $table->unsignedSmallInteger('priority')->default(100);

            // Never message the same person twice inside this many days, counted
            // across EVERY rule rather than per rule. Null falls back to config.
            $table->unsignedSmallInteger('cooldown_days')->nullable();

            // Lifetime ceiling per customer for this rule. Null = no ceiling.
            $table->unsignedSmallInteger('max_per_customer')->nullable();

            /*
             * Ask a fraction rather than everybody. 100 = everybody, which is
             * where this starts.
             *
             * At a busy branch you do not need every customer's opinion, and a
             * fifth of the sends is also a fifth of the irritation.
             */
            $table->unsignedTinyInteger('sample_rate')->default(100);

            $table->foreignId('created_by_user_id')->constrained('users');

            $table->timestamps();
            $table->softDeletes();

            // The evaluator reads active rules in priority order on every
            // completed order.
            $table->index(['is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};
