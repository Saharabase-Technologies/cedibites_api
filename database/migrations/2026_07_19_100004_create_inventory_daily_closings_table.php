<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mandatory end-of-day stock count for a location. Expected quantities are
 * snapshotted from the ledger when the count is opened; the operator enters the
 * physically counted quantities and completes it, locking in the variance. One
 * closing per (location, business_date); a date with no closing is a "missed day".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_daily_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->date('business_date');
            $table->string('status', 16)->default('open');
            $table->text('notes')->nullable();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['location_id', 'business_date']);
            $table->index(['location_id', 'status']);
            $table->index('business_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_daily_closings');
    }
};
