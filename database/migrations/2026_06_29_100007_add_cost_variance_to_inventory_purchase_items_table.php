<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captures cost deviation on receipt: the PO line's estimated unit cost snapshot
 * and the variance against what was actually paid. Mirrors the quantity variance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_purchase_items', function (Blueprint $table) {
            $table->decimal('expected_unit_cost', 14, 4)->nullable()->after('variance_reason');
            $table->decimal('cost_variance', 14, 4)->nullable()->after('expected_unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_purchase_items', function (Blueprint $table) {
            $table->dropColumn(['expected_unit_cost', 'cost_variance']);
        });
    }
};
