<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per receipt that comes off a printer.
 *
 * The order row already carried `receipt_printed_at` and `receipt_print_count`,
 * which between them answer "was it printed" and "how many times" and nothing
 * else. The first is written once and never updated, so it is the original and
 * only the original; the count is a bare total. When a slip from Ashaiman
 * turned up with a reprint apparently stamped an hour before its own original,
 * the timeline could be reconstructed only by inference: four prints existed,
 * one timestamp existed, and who pressed the button three of those times was
 * simply not recorded anywhere.
 *
 * A receipt is the document a customer brings back when there is a dispute, so
 * being unable to say when one was produced and by whom is a real gap rather
 * than a tidiness problem. This closes it.
 *
 * `printed_at` is the server's time, always. That is the whole point: the
 * machine doing the printing is exactly the thing whose clock cannot be
 * trusted, and it was a till an hour behind that prompted this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_receipt_prints', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Nullable rather than required. A print recorded without a
            // resolvable employee is still worth keeping — losing the row
            // because the person could not be identified would throw away the
            // timestamp too, which is the part that settles a dispute.
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // 'original' or 'reprint', as printed on the slip itself.
            $table->string('kind', 16);

            // Which reprint this was: 1, 2, 3. Null on an original.
            $table->unsignedInteger('reprint_number')->nullable();

            // How many physical slips this press produced. An original is two.
            $table->unsignedSmallInteger('copies')->default(1);

            // Which screen the button was on, so "how was this available?" is
            // answerable without reading the frontend.
            $table->string('source', 32)->nullable();

            $table->timestamp('printed_at');
            $table->timestamps();

            // Every question this table exists to answer starts with an order.
            $table->index(['order_id', 'printed_at']);
            // "What did this person print today" is the other one.
            $table->index(['employee_id', 'printed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_receipt_prints');
    }
};
