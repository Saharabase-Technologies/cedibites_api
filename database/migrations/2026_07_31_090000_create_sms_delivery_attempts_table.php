<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per SMS send attempt, successes included.
 *
 * failed_jobs already records failures, but it cannot answer the question that
 * matters — "is SMS working right now?" — because it holds no successes to
 * measure against, and a retried or pruned job leaves no trace. Without a
 * denominator a dead pipe and a quiet evening look identical.
 *
 * Pruned by sms:health-check; this is an operational signal, not an archive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_delivery_attempts', function (Blueprint $table) {
            $table->id();

            // Class basename of the notification, or null for direct sends
            // (e.g. the login OTP, which goes through the service, not a channel).
            $table->string('notification')->nullable();

            $table->string('recipient', 32)->nullable();
            $table->boolean('succeeded');

            // App\Enums\SmsFailureReason — null on success.
            $table->string('failure_reason', 32)->nullable();
            $table->text('error_message')->nullable();
            $table->string('message_id')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // The health window query: recent rows, split by outcome.
            $table->index(['created_at', 'succeeded']);
            $table->index('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_delivery_attempts');
    }
};
