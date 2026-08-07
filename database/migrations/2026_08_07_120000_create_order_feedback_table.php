<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's verdict on one order.
 *
 * Deliberately named apart from the two other things in this codebase called
 * feedback: `feedback_reports` is the in-app beta bug reporter, and
 * `menu_item_ratings` is per-dish stars. This is neither — it is one person on
 * one order, and it lives at /admin/customer-feedback so nobody has to work out
 * which is which.
 *
 * The token identifies the order, so the form already knows what they ate and
 * from which branch. They never type an order number, and there is no login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_feedback', function (Blueprint $table) {
            $table->id();

            // Unique: one request per order, enforced by the database rather than
            // by the job remembering. A retried queue job must not text somebody
            // twice about the same meal.
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();

            // Eight base62 characters, random and never sequential. Longer than a
            // campaign token because this one carries identity — guessing it in
            // bulk would let somebody walk order history and poison the data.
            $table->string('token', 16)->unique();

            $table->unsignedTinyInteger('rating_overall')->nullable();
            $table->unsignedTinyInteger('rating_food')->nullable();
            $table->unsignedTinyInteger('rating_service')->nullable();

            $table->text('comment')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('expires_at');

            $table->timestamps();

            // The admin list is "what came back, newest first"; the response-rate
            // figure is submitted over sent.
            $table->index('submitted_at');
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_feedback');
    }
};
