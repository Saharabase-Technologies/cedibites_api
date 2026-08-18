<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-person state for one send. This table is the whole deterrent: a caution
 * nobody can prove was read changes nothing, and "seen by 12 of 40" on the
 * sender's screen is what makes the difference.
 *
 * Read and acknowledged are separate columns because they are separate claims.
 * Opening the bell marks read; pressing the button on a caution marks
 * acknowledged. Collapsing them would let a glance count as an undertaking.
 *
 * `quick_reply` and `reply_body` are also separate. A quick reply is one of a
 * fixed set the sender offered, so it aggregates — "31 said Understood" is a
 * number. Free text never aggregates and is read individually. Storing the
 * canned answer in the free-text column would destroy that count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_message_recipients', function (Blueprint $table) {
            $table->id();

            $table->foreignId('staff_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The branch this person held when the message went out. Denormalised
            // on purpose: reading it back through the pivot later answers where
            // they are now, not where they were when they were cautioned.
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();

            $table->string('quick_reply')->nullable();
            $table->text('reply_body')->nullable();
            $table->timestamp('replied_at')->nullable();

            $table->timestamp('sms_sent_at')->nullable();
            $table->string('sms_status')->nullable();

            $table->timestamps();

            // One row per person per message. Without this a retried dispatch
            // silently doubles the recipient count and every percentage computed
            // from it.
            $table->unique(['staff_message_id', 'user_id']);

            // The inbox query: my unread messages, newest first.
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_message_recipients');
    }
};
