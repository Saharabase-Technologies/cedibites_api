<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stock-take reconciliation cycle for a location. Opens with a system-quantity
 * snapshot, the operator counts everything, and posting the adjustments cancels
 * the variance out (cycle_adjustment movements) and closes the cycle — resetting
 * the books to the physical actual. One open cycle per location at a time
 * (enforced in the service). Manager-initiated, not calendar-driven.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reconciliation_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->string('status', 16)->default('open');
            $table->text('notes')->nullable();
            // Signed value of all posted variances (counted − system) × unit cost.
            $table->decimal('net_variance_value', 16, 4)->nullable();
            // Variance-value threshold snapshot used to flag over-threshold lines.
            $table->decimal('threshold_amount', 14, 4)->nullable();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['location_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reconciliation_cycles');
    }
};
