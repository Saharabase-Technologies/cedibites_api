<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A promo with a code waits to be asked for. A promo without one applies by
 * itself, as every promo has until now, so existing rows keep behaving exactly
 * as they did.
 *
 * Uses are counted off `orders.promo_id` rather than a table of their own. The
 * order already records the promo, the discount and the phone, so a second
 * record of the same fact could only ever disagree with the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->after('name');
            $table->unsignedInteger('max_uses')->nullable()->after('max_discount');
            $table->unsignedInteger('max_uses_per_customer')->nullable()->after('max_uses');
            $table->boolean('first_order_only')->default(false)->after('max_uses_per_customer');
        });

        // Unique among live promos only. A deleted promo keeps its code for the
        // record, and that must not stop somebody reusing the word next month.
        // Partial indexes are valid on both Postgres and SQLite.
        DB::statement('CREATE UNIQUE INDEX promos_code_live_unique ON promos (code) WHERE deleted_at IS NULL AND code IS NOT NULL');

        // Every code check counts orders by promo. The foreign key does not
        // index the column on Postgres.
        Schema::table('orders', function (Blueprint $table) {
            $table->index('promo_id', 'orders_promo_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_promo_id_index');
        });

        DB::statement('DROP INDEX IF EXISTS promos_code_live_unique');

        Schema::table('promos', function (Blueprint $table) {
            $table->dropColumn(['code', 'max_uses', 'max_uses_per_customer', 'first_order_only']);
        });
    }
};
