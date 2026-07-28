<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-location reorder thresholds.
 *
 * `inventory_items.reorder_level` / `min_threshold` are single global figures,
 * and they were set at central-warehouse scale (1000 carrier bags, 150 L of
 * frying oil). A branch holding one day of cover is judged against a warehouse's
 * reorder point and reads Critical on nearly every line - not because it is
 * short, but because the number it is compared to belongs to somebody else.
 *
 * One threshold cannot serve a warehouse holding 400 kg and a branch holding 28.
 * A row here overrides the item's global figure for one location; absent, the
 * global still applies, so nothing has to be filled in for this to be safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_item_location_thresholds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->cascadeOnDelete();
            // Null on either column means "fall back to the item's global figure",
            // so a location can override just the reorder point and inherit the
            // critical minimum, or the other way round.
            $table->decimal('reorder_level', 12, 3)->nullable();
            $table->decimal('min_threshold', 12, 3)->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'location_id']);
            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_item_location_thresholds');
    }
};
