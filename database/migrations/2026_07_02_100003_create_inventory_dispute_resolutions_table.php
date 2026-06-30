<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permanent dispute record for a short/over receipt. The disputed transfer is
 * never edited; resolution creates a NEW corrective transfer (corrective_transfer_id)
 * and flips this row to resolved. One open dispute per transfer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_dispute_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('inventory_transfers')->restrictOnDelete();
            $table->string('status', 16)->default('open'); // open | resolved
            $table->foreignId('raised_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->decimal('discrepancy_qty', 14, 4)->default(0); // total sent - received across lines
            $table->foreignId('corrective_transfer_id')->nullable()->constrained('inventory_transfers')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['transfer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_dispute_resolutions');
    }
};
