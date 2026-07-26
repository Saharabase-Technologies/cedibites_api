<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a dispute was settled.
 *
 * Resolving a dispute always spawned a corrective transfer to make up the
 * shortfall. But sometimes the stock is simply gone and chasing it with another
 * delivery is fiction — the warehouse wants to accept the loss and close it.
 *
 * No stock movement is involved either way: the shortfall already left the
 * source at `send` and never arrived at the destination, so the ledger has
 * recorded the loss from the moment of the short receipt. What was missing was
 * the record of the DECISION, which is what wastage reporting will need.
 *
 * `resolution` is nullable so rows resolved before this migration stay honest
 * about not knowing — they are not silently relabelled as corrective.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_dispute_resolutions', function (Blueprint $table) {
            $table->string('resolution', 16)->nullable()->after('status'); // corrective | written_off
            $table->decimal('written_off_qty', 14, 4)->default(0)->after('discrepancy_qty');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_dispute_resolutions', function (Blueprint $table) {
            $table->dropColumn(['resolution', 'written_off_qty']);
        });
    }
};
