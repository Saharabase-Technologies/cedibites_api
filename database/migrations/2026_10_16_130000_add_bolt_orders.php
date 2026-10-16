<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bolt Food orders, rung at the till until Bolt's own feed is connected.
 *
 * A dish on Bolt has Bolt's own price, kept beside ours on the option, and
 * sometimes a Bolt promo that we pay for. An order from Bolt carries Bolt's
 * order code, so it can be matched to Bolt's report, and Bolt's cut, so net
 * sales count only what we keep. The owner's rule, 2026-10-14: counting the
 * full Bolt price "would be inflating our books".
 *
 * The `platform_` names are not Bolt's alone: another delivery app would use
 * the same four columns.
 */
return new class extends Migration
{
    private const SOURCES_BEFORE = [
        'online', 'phone', 'whatsapp', 'social_media',
        'instagram', 'facebook', 'pos', 'manual_entry',
    ];

    private const METHODS_BEFORE = [
        'mobile_money', 'card', 'wallet', 'ghqr', 'cash', 'no_charge', 'manual_momo',
    ];

    public function up(): void
    {
        Schema::table('menu_item_options', function (Blueprint $table) {
            // Null means the dish is not sold on Bolt.
            $table->decimal('bolt_price', 10, 2)->nullable()->after('price');
            // Bolt's promo on this dish, which we pay for. Null means none.
            $table->decimal('bolt_promo_percent', 5, 2)->nullable()->after('bolt_price');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Bolt's own code for the order, as the tablet shows it (WWJ98).
            $table->string('platform_reference', 40)->nullable()->after('order_source');
            // What Bolt keeps, worked out when the order was placed.
            $table->decimal('platform_commission', 10, 2)->default(0)->after('discount');
            // The rate and what it was taken from, as they stood then, so a
            // later change to the setting never rewrites an old order.
            $table->decimal('platform_commission_rate', 5, 2)->nullable()->after('platform_commission');
            $table->string('platform_commission_base', 10)->nullable()->after('platform_commission_rate');

            $table->index(['order_source', 'platform_reference']);
        });

        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->string('platform_reference', 40)->nullable()->after('order_source');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->replaceEnumConstraint('orders', 'order_source', [...self::SOURCES_BEFORE, 'bolt']);
            $this->replaceEnumConstraint('payments', 'payment_method', [...self::METHODS_BEFORE, 'bolt']);
        }
    }

    public function down(): void
    {
        // The constraints stay wide on the way down. Rows written while this
        // was up would break the narrower ones, and a rollback that fails
        // halfway is worse than a constraint that allows one value too many.
        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->dropColumn('platform_reference');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['order_source', 'platform_reference']);
            $table->dropColumn([
                'platform_reference',
                'platform_commission',
                'platform_commission_rate',
                'platform_commission_base',
            ]);
        });

        Schema::table('menu_item_options', function (Blueprint $table) {
            $table->dropColumn(['bolt_price', 'bolt_promo_percent']);
        });
    }

    /**
     * Drop every check constraint on a column and add one with these values.
     *
     * @param  array<int, string>  $values
     */
    private function replaceEnumConstraint(string $table, string $column, array $values): void
    {
        $constraints = DB::select("
            SELECT con.conname
            FROM pg_constraint con
            JOIN pg_class rel ON rel.oid = con.conrelid
            WHERE rel.relname = ?
              AND con.contype = 'c'
              AND pg_get_constraintdef(con.oid) LIKE ?
        ", [$table, '%'.$column.'%']);

        foreach ($constraints as $constraint) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint->conname}");
        }

        $list = collect($values)->map(fn ($v) => "'".$v."'")->implode(', ');

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column}::text = ANY (ARRAY[{$list}]::text[]))");
    }
};
