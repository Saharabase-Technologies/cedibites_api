<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The code behind the QR on a printed receipt.
 *
 * A customer scans the slip and lands on a public page that says whether this
 * receipt is one we actually issued. That check is only worth anything if the
 * code cannot be guessed: if the QR carried the order number, anybody could
 * print a convincing forgery whose QR points at a real order, and it would
 * verify clean. So this is random and unique, and it is the only thing the
 * public endpoint will accept.
 *
 * Nullable because it has to be. Every order already in the table predates this
 * column, and a receipt reprinted for one of them still needs to verify, so the
 * backfill below gives them all a code rather than leaving a class of orders
 * that can never be checked.
 */
return new class extends Migration
{
    /** Long enough that guessing is hopeless, short enough to sit under a QR. */
    private const CODE_LENGTH = 24;

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('receipt_verification_code', 32)
                ->nullable()
                ->unique()
                ->after('receipt_print_count');
        });

        // Backfill in chunks. Doing it row by row over the whole table would be
        // one query per order on a table that grows forever; doing it in one
        // statement would need a per-row random in SQL, which is not portable
        // between the Postgres we run and the SQLite the tests use.
        DB::table('orders')->select('id')->orderBy('id')->chunk(500, function ($orders) {
            foreach ($orders as $order) {
                DB::table('orders')
                    ->where('id', $order->id)
                    ->update(['receipt_verification_code' => Str::random(self::CODE_LENGTH)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('receipt_verification_code');
        });
    }
};
