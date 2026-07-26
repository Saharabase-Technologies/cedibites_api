<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reason belongs wherever a loss is declared, not only on a manual wastage.
 *
 * A counted shortfall with no reason is just a number nobody can act on. Giving
 * both count sheets the same reason vocabulary turns "rice is 3 kg short" into
 * "3 kg of rice burnt", which is the difference between a report you read and a
 * report you file.
 *
 * `adjustment_movement_id` on a closing line records the `count_adjustment` that
 * brought the ledger to what was actually counted — the mechanism that lets
 * tomorrow open on last night's counted closing balance rather than on a figure
 * the ledger merely hoped for. The reconciliation line already had its
 * equivalent.
 *
 * `wastage_id` on the parent documents points at the single classification
 * record raised for that count, so the reasons show up in wastage reporting
 * without any line ever posting stock twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_daily_closing_lines', function (Blueprint $table) {
            $table->string('reason', 32)->nullable()->after('variance');
            $table->text('reason_note')->nullable()->after('reason');
            $table->unsignedBigInteger('adjustment_movement_id')->nullable()->after('reason_note');
        });

        Schema::table('inventory_daily_closings', function (Blueprint $table) {
            $table->unsignedBigInteger('wastage_id')->nullable()->after('status');
        });

        Schema::table('inventory_reconciliation_lines', function (Blueprint $table) {
            $table->string('reason', 32)->nullable()->after('over_threshold');
            $table->text('reason_note')->nullable()->after('reason');
        });

        Schema::table('inventory_reconciliation_cycles', function (Blueprint $table) {
            $table->unsignedBigInteger('wastage_id')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_daily_closing_lines', function (Blueprint $table) {
            $table->dropColumn(['reason', 'reason_note', 'adjustment_movement_id']);
        });

        Schema::table('inventory_daily_closings', function (Blueprint $table) {
            $table->dropColumn('wastage_id');
        });

        Schema::table('inventory_reconciliation_lines', function (Blueprint $table) {
            $table->dropColumn(['reason', 'reason_note']);
        });

        Schema::table('inventory_reconciliation_cycles', function (Blueprint $table) {
            $table->dropColumn('wastage_id');
        });
    }
};
