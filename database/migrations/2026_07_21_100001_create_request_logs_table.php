<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rolling per-request diagnostic log — NOT an audit trail. One summary row per
 * API request (written fail-open by middleware), plus a traceback on any 5xx.
 * Keyed by X-Request-ID so a feedback report can pull exactly the backend lines
 * for one user's actions. Purged on a retention schedule; deliberately no
 * updated_at / soft-deletes — high write volume wants a cheap auto pk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_logs', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 64)->index(); // the correlation join key
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method', 10);
            $table->string('path');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('level', 8)->default('info'); // info | error
            $table->text('message')->nullable();          // traceback on error, capped
            $table->timestamp('created_at')->index();      // powers ±window fallback + purge
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_logs');
    }
};
