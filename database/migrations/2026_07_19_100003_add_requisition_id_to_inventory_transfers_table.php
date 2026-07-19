<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a transfer back to the requisition it fulfils. When such a transfer is
 * received (in full), the requisition flips to `fulfilled`. Carried across to the
 * corrective transfer when a fulfilling transfer is disputed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->foreignId('requisition_id')
                ->nullable()
                ->after('parent_transfer_id')
                ->constrained('inventory_requisitions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requisition_id');
        });
    }
};
