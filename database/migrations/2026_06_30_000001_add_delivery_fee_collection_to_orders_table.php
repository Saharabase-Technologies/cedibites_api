<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Track third-party delivery-fee collection separately from the goods payment.
 *
 * Delivery is collected by the rider on delivery (cash) — never through the
 * restaurant's payment rails — so we track its collection state on the order:
 *   - not_applicable: no delivery fee (pickup / dine-in / free delivery)
 *   - pending:        delivery fee owed, not yet collected by the rider
 *   - collected:      rider has collected the delivery fee
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_fee_status', 20)->default('not_applicable')->after('delivery_fee');
            $table->timestamp('delivery_fee_collected_at')->nullable()->after('delivery_fee_status');
            $table->unsignedBigInteger('delivery_fee_collected_by')->nullable()->after('delivery_fee_collected_at');

            $table->foreign('delivery_fee_collected_by')->references('id')->on('employees')->nullOnDelete();
        });

        // Backfill existing orders. A delivery fee > 0 is the signal that there is
        // something to collect; delivered/completed delivery orders are treated as
        // already collected (rider handed it over on delivery).
        DB::table('orders')
            ->where('delivery_fee', '>', 0)
            ->whereIn('status', ['delivered', 'completed'])
            ->update([
                'delivery_fee_status' => 'collected',
                'delivery_fee_collected_at' => DB::raw('COALESCE(actual_delivery_time, updated_at)'),
                'delivery_fee_collected_by' => DB::raw('assigned_employee_id'),
            ]);

        DB::table('orders')
            ->where('delivery_fee', '>', 0)
            ->whereNotIn('status', ['delivered', 'completed'])
            ->update(['delivery_fee_status' => 'pending']);

        // Everything else keeps the 'not_applicable' default.
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['delivery_fee_collected_by']);
            $table->dropColumn(['delivery_fee_status', 'delivery_fee_collected_at', 'delivery_fee_collected_by']);
        });
    }
};
