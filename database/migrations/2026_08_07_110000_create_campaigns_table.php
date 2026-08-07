<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per SMS campaign, and the permanent record of what it did.
 *
 * The counts and costs on this row are the trap in this phase. Per-recipient
 * detail lives in `sms_delivery_attempts`, which is PRUNED by sms:health-check —
 * so reporting that read its figures from there would watch campaign history
 * evaporate at the retention boundary, and a report shown to the board last
 * month would return different numbers this month.
 *
 * So the aggregates are rolled up as chunks complete and written here, and this
 * table is never pruned. The attempt rows stay disposable and are used only for
 * the delivery-status poll, which runs within hours of the send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->text('message');

            // One of the six the contact export already resolves. Stored as the
            // string rather than a set of filters so the audience can be
            // re-resolved and re-counted at send time — a segment is a
            // description of people, and the people change.
            $table->string('segment', 32);

            $table->string('status', 32)->default('draft');

            $table->timestamp('scheduled_for')->nullable();

            // The link in the message, if there is one. Nullable — plenty of
            // campaigns are pure text.
            $table->foreignId('short_link_id')->nullable()->constrained()->nullOnDelete();

            // Permanent aggregates. See the note above.
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            // Projected before the send from the configured rate; measured after
            // it from what Hubtel actually charged. Both are kept, because the
            // gap between them is the thing worth knowing.
            $table->decimal('estimated_cost', 10, 4)->default(0);
            $table->decimal('actual_cost', 10, 4)->nullable();
            $table->unsignedSmallInteger('segments_per_message')->default(1);

            $table->foreignId('created_by_user_id')->constrained('users');

            // Composing and approving are two acts, and the second is the one
            // that spends the money. Recorded separately even when it is the
            // same person twice.
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('scheduled_for');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
