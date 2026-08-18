<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rule that sends a staff message on its own when something measurable happens.
 *
 * Built as a general engine rather than as "the stalled-order alert". The first
 * use is orders sitting unmoved, but the same machinery covers junk phone
 * numbers, cancellation spikes and shifts left open, and naming it after its
 * first use would mean building it a second time.
 *
 * `is_active` defaults to false and there is a separate global kill switch in
 * config. They answer different questions — "is this rule live?" versus "is the
 * feature live?" — and switching the feature on must not switch on every draft
 * somebody left half-written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_message_rules', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('description')->nullable();

            // See App\Enums\StaffMessageEvent.
            $table->string('event')->index();

            // Event-specific settings: the status and minute count for a stall,
            // the threshold and window for a spike. Validated per event, and an
            // event whose required setting is missing is REFUSED rather than
            // defaulted — a default here is a rule that matches something nobody
            // chose.
            $table->json('conditions')->nullable();

            // Composable: actor, branch_managers, branch_staff, roles[], admins.
            $table->json('target')->nullable();

            $table->string('kind')->default('caution');
            $table->string('subject')->nullable();
            $table->text('body_template');

            $table->boolean('requires_acknowledgement')->default(true);
            $table->boolean('allow_custom_reply')->default(true);
            $table->json('quick_replies')->nullable();
            $table->unsignedInteger('sms_fallback_after_minutes')->nullable();

            // One nag per subject per rule. Without it a stalled order produces a
            // message every time the scheduler runs, forever, which trains people
            // to ignore the channel entirely.
            $table->unsignedInteger('cooldown_minutes')->default(1440);

            // Highest wins when several rules match the same subject and person.
            $table->unsignedInteger('priority')->default(0);

            $table->boolean('is_active')->default(false)->index();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_message_rules');
    }
};
