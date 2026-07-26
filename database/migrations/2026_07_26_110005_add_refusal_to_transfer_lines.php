<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refusing part of a delivery.
 *
 * Until now a line could only be received short, and the system read every
 * shortfall the same way: the goods never arrived. But "you sent 10 and 8 turned
 * up" and "you sent 10, all 10 turned up, and 2 of them are rotten so they are
 * going back on the lorry" are different events with different owners. The first
 * is a disagreement to settle; the second is agreed by both ends the moment it
 * happens.
 *
 *   received_qty  — accepted, added to the destination.
 *   refused_qty   — arrived, rejected, sent straight back to the source.
 *   the remainder — never turned up. That, and only that, is a dispute.
 *
 * `refuse_reason` uses the shared WastageReason vocabulary, so refused goods
 * land in the warehouse's wastage queue already carrying the branch's account of
 * what was wrong with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfer_lines', function (Blueprint $table) {
            $table->decimal('refused_qty', 14, 4)->nullable()->after('received_qty');
            $table->string('refuse_reason', 32)->nullable()->after('refused_qty');
            $table->text('refuse_note')->nullable()->after('refuse_reason');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transfer_lines', function (Blueprint $table) {
            $table->dropColumn(['refused_qty', 'refuse_reason', 'refuse_note']);
        });
    }
};
