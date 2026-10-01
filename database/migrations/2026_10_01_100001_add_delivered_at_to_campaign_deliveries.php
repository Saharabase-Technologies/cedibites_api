<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the poller first saw a message as delivered.
     *
     * The delivery curve ("how many had arrived by one hour, by six") was read
     * off `updated_at`. The poller rewrites `updated_at` on every row every
     * fifteen minutes for two days, so by the end of the window every message
     * looked as though it had arrived in the last quarter of an hour, and the
     * early marks on the curve read zero. This column is written once and never
     * moved.
     */
    public function up(): void
    {
        Schema::table('campaign_deliveries', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable();
        });

        // Rows that are already delivered have lost the moment it happened.
        // `created_at` is when the poller first wrote the row, which is the
        // earliest it can have been and the closest figure still on record.
        DB::table('campaign_deliveries')
            ->where('outcome', 'delivered')
            ->whereNull('delivered_at')
            ->update(['delivered_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('campaign_deliveries', function (Blueprint $table) {
            $table->dropColumn('delivered_at');
        });
    }
};
