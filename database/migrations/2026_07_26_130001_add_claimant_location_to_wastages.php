<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who OBSERVED the loss, as distinct from who owns it.
 *
 * Refusing a delivery raises a wastage claim at the SOURCE, because goods turned
 * away at the door never stopped being the sender's. That is right for
 * ownership and wrong for access: the claim was scoped to the source alone, so
 * the branch manager who actually opened the crate and saw the spoiled chicken
 * could not see the claim at all, let alone attach the photograph that only
 * they are in a position to take.
 *
 * `claimant_location_id` is the location whose staff raised the claim. For a
 * refused delivery that is the destination; for everything else it is the same
 * as `location_id`. Adding it to the visibility scope lets both ends of a
 * disagreement see it and put their evidence on the record, while approval
 * stays with the location that carries the loss.
 *
 * Existing delivery-rejection rows are backfilled from their originating
 * transfer, so claims raised before this migration are not left invisible to
 * the people who raised them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_wastages', function (Blueprint $table) {
            $table->foreignId('claimant_location_id')->nullable()->after('disposal_location_id')
                ->constrained('inventory_locations')->nullOnDelete();
        });

        // Everything raised where it was observed: claimant = owner.
        DB::table('inventory_wastages')
            ->where('origin', '!=', 'delivery_rejection')
            ->update(['claimant_location_id' => DB::raw('location_id')]);

        // Refusals: the observer is the destination of the transfer it came from.
        DB::table('inventory_wastages')
            ->where('origin', 'delivery_rejection')
            ->whereNotNull('source_id')
            ->update([
                'claimant_location_id' => DB::raw(
                    '(select destination_location_id from inventory_transfers'
                    .' where inventory_transfers.id = inventory_wastages.source_id)'
                ),
            ]);
    }

    public function down(): void
    {
        Schema::table('inventory_wastages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('claimant_location_id');
        });
    }
};
