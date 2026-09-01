<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Acknowledgements for the platform error feed.
     *
     * The feed is derived, not stored: every item is recomputed from activity
     * logs, `failed_jobs` and the log file on each request. So an
     * acknowledgement cannot live on the error — there is no row to mark. It
     * lives here instead, keyed on a fingerprint the service recomputes from
     * the error's shape rather than its text, so the same fault recurring
     * tomorrow matches the acknowledgement made today.
     */
    public function up(): void
    {
        Schema::create('acknowledged_errors', function (Blueprint $table) {
            $table->id();

            // Stable hash of category + normalised title. NOT the feed's `id`,
            // which for log-file exceptions carries a positional index and
            // changes every time the log grows.
            $table->string('fingerprint', 64)->unique();

            // Kept for the audit trail: what was actually on screen when
            // somebody dismissed it. The fingerprint alone is unreadable.
            $table->string('title');
            $table->string('category', 40)->nullable();
            $table->string('severity', 20)->nullable();

            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();

            // Doubles as the watermark. The feed hides an acknowledged fault
            // only while its newest occurrence is older than this; the moment
            // it happens again the item comes back, unacknowledged. That is
            // what makes "acknowledge" mean "I have dealt with this one" and
            // not "never show me this again".
            $table->timestamp('acknowledged_at');

            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('acknowledged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acknowledged_errors');
    }
};
