<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Option A — item-level "purchase pack" (buy-in-packs-of).
 *
 * Lets an item be counted/consumed in its base unit (e.g. Eggs in `piece`)
 * while being purchased in packs (e.g. a `crate` of 30). Stored on the item so
 * the record-purchase form can offer a "10 crates → 300 pieces" helper.
 * Recipes, counts and reconciliation stay entirely in the base unit — untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            // Label of the pack the item is bought in (e.g. "crate", "carton", "sack").
            $table->string('purchase_pack_label', 32)->nullable()->after('min_threshold');
            // How many base units are in one pack (e.g. 30 pieces per crate).
            $table->decimal('purchase_pack_size', 12, 3)->nullable()->after('purchase_pack_label');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn(['purchase_pack_label', 'purchase_pack_size']);
        });
    }
};
