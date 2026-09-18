<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * One-off codes: a batch of vouchers for one promo, each good for one order.
 *
 * How a promo reaches an order becomes a column of its own, `redemption`,
 * rather than something read off whether a code happens to be set. A promo
 * meant for one-off codes has no code of its own and, until somebody makes
 * the batch, no codes at all. Read off the data, that looks exactly like a
 * promo that applies by itself, and it would have gone to every order.
 *
 *   automatic    applies by itself to every order that qualifies
 *   shared_code  one code, `promos.code`, typed by anybody who has it
 *   single_use   a batch in `promo_codes`, each code good once
 *
 * A one-off code is used when an order carries it, so the order records which
 * one (`orders.promo_code_id`) and the code keeps no used flag of its own that
 * could disagree. The checkout session carries it too, so a code on a Mobile
 * Money payment still in progress is held for the five minutes it takes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            $table->string('redemption', 20)->default('automatic')->after('code');
        });

        DB::table('promos')->whereNotNull('code')->update(['redemption' => 'shared_code']);

        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_id')->constrained()->cascadeOnDelete();
            // As printed, dash and all: JOLLOF-K7Q2MX.
            $table->string('code', 32);
            // What is matched: capitals, no dashes or spaces, so a code typed
            // without its dash still finds itself.
            $table->string('lookup', 32)->unique();
            // A name for the batch it was made in, e.g. "Radio giveaway".
            $table->string('batch', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('promo_id');
        });

        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->foreignId('promo_code_id')->nullable()->after('promo_name')
                ->constrained('promo_codes')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('promo_code_id')->nullable()->after('promo_name')
                ->constrained('promo_codes')->nullOnDelete();
            $table->index('promo_code_id', 'orders_promo_code_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['promo_code_id']);
            $table->dropIndex('orders_promo_code_id_index');
            $table->dropColumn('promo_code_id');
        });

        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->dropForeign(['promo_code_id']);
            $table->dropColumn('promo_code_id');
        });

        Schema::dropIfExists('promo_codes');

        Schema::table('promos', function (Blueprint $table) {
            $table->dropColumn('redemption');
        });
    }
};
