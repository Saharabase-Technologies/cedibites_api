<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What device a session is actually on.
     *
     * The sessions panel could name the person but not the machine, so an admin
     * looking at three sessions for one cashier had no way to tell the till from
     * the phone in their pocket — which is exactly the choice "sign this one
     * out" asks them to make.
     *
     * The raw user agent is stored rather than a derived label so the
     * classification can be corrected later without a backfill. Existing tokens
     * keep a null and read as "unknown device" until their next sign-in.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->text('user_agent')->nullable()->after('last_used_at');
            $table->string('ip_address', 45)->nullable()->after('user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['user_agent', 'ip_address']);
        });
    }
};
