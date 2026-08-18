<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (rule, subject, recipient) the evaluator considered — including the
 * ones it decided NOT to send.
 *
 * Recording only the sends is the trap. A rule that matches three hundred orders
 * and sends four is working exactly as designed, and that is indistinguishable
 * from a broken rule unless the other two hundred and ninety-six are written down
 * with the reason they were held back. Every complaint about this feature will be
 * either "why did it send that?" or "why did it not send?", and this table is the
 * only thing that answers the second one.
 *
 * It is also what enforces the cooldown: the check is a lookup here, so a fire
 * suppressed for cooldown still writes a row and still does not reset the window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_message_rule_fires', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rule_id')->constrained('staff_message_rules')->cascadeOnDelete();

            // The thing the rule fired about — an order, a shift. Nullable
            // because a spike rule is about a person over a window, not about one
            // record.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            // Null when suppressed — no message was created.
            $table->foreignId('staff_message_id')->nullable()->constrained('staff_messages')->nullOnDelete();

            // cooldown | recipient_capped | rule_inactive | feature_off |
            // no_recipients | already_resolved | lower_priority
            // Null means it actually sent.
            $table->string('suppressed_reason')->nullable()->index();

            $table->timestamp('fired_at')->useCurrent();

            $table->timestamps();

            // The cooldown lookup: has this rule already fired at this person
            // about this subject?
            $table->index(['rule_id', 'user_id', 'subject_type', 'subject_id'], 'smrf_cooldown_idx');

            // The per-recipient hourly ceiling, across all rules.
            $table->index(['user_id', 'fired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_message_rule_fires');
    }
};
