<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carry the order's channel through the checkout session, and let the orders
 * table record `social_media`.
 *
 * The staff order screen has always asked "where did this order come from?" —
 * phone, WhatsApp, social — and refused to submit without an answer. It then
 * threw the answer away: the payload never carried it, and OrderCreationService
 * wrote `pos` for everything that was not an online order. So every order the
 * call centre has ever taken is recorded as a walk-in at the counter, and the
 * channel breakdown in analytics cannot tell a phone order from a till sale.
 *
 * Two things were needed. The session is where an order is assembled before it
 * exists, so the channel has to live there to survive the trip. And the check
 * constraint on orders.order_source allowed `instagram` and `facebook` but not
 * the `social_media` the UI has always offered — which never failed only because
 * the value never reached the database. The older two are kept: they are valid
 * historical values and dropping them would orphan existing rows.
 */
return new class extends Migration
{
    private const SOURCES = [
        'online', 'phone', 'whatsapp', 'social_media',
        'instagram', 'facebook', 'pos', 'manual_entry',
    ];

    public function up(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->string('order_source', 20)->nullable()->after('session_type');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->replaceEnumConstraint('orders', 'order_source', self::SOURCES);
        }
    }

    public function down(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->dropColumn('order_source');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Leave `social_media` in place on the way down. Rows written while this
        // migration was up would violate the narrower constraint, and a rollback
        // that fails halfway is worse than a constraint that stays wide.
        $this->replaceEnumConstraint('orders', 'order_source', self::SOURCES);
    }

    /**
     * Drop all check constraints on a column and add a new one with the given values.
     *
     * @param  array<int, string>  $values
     */
    private function replaceEnumConstraint(string $table, string $column, array $values): void
    {
        $constraints = DB::select("
            SELECT con.conname
            FROM pg_constraint con
            JOIN pg_class rel ON rel.oid = con.conrelid
            JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
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
