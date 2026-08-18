<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per send. A notice broadcast to forty riders is ONE row here and forty
 * in `staff_message_recipients`.
 *
 * Laravel's own `notifications` table was the obvious home and is the wrong one.
 * It holds an opaque JSON blob per user with no notion that forty rows were the
 * same act, no reply, no acknowledgement and no thread — so "how many people have
 * seen this?", the entire point of the feature, is unanswerable from it.
 *
 * `audience` is stored as chosen rather than only as the resolved recipient list.
 * The list answers "who got it"; the audience answers "who was it meant for", and
 * those diverge the moment somebody changes branch or leaves. Both are worth
 * keeping and neither reconstructs the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_messages', function (Blueprint $table) {
            $table->id();

            // Null sender means the rule engine sent it. Deliberately nullable
            // rather than pointing at a service account: a service account shows
            // up in the staff list, can be assigned a branch, and eventually
            // somebody tries to log in as it.
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Set when automation created this. Kept as an unconstrained id
            // because rules are deletable and the message must outlive the rule
            // that sent it — the audit trail is the point.
            $table->unsignedBigInteger('rule_id')->nullable()->index();

            // How a thread continues. A quick reply lives on the recipient row;
            // anything longer becomes a child message pointing here.
            $table->foreignId('parent_id')->nullable()->constrained('staff_messages')->cascadeOnDelete();

            // notice   — sits in the bell, never interrupts
            // caution  — interrupts once the till is idle, usually needs an ack
            // direct   — one named person, threaded
            // staff_query — the upward one: a staff member asking the IT team
            $table->string('kind')->default('notice')->index();

            $table->string('subject')->nullable();
            $table->text('body');

            $table->json('audience')->nullable();

            $table->boolean('requires_acknowledgement')->default(false);
            $table->boolean('allow_custom_reply')->default(true);
            $table->json('quick_replies')->nullable();

            // Null means never escalate. Zero would mean "escalate immediately",
            // which is a different and legitimate setting, so the two must not
            // collapse into one value.
            $table->unsignedInteger('sms_fallback_after_minutes')->nullable();

            $table->timestamp('expires_at')->nullable();

            // Null until sent. A message is a draft until this is stamped, and
            // nothing is delivered, counted or escalated before then.
            $table->timestamp('sent_at')->nullable()->index();

            $table->unsignedInteger('recipient_count')->default(0);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_messages');
    }
};
