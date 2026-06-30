<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical stock movement between two locations (mother kitchen ⇄ satellites).
 * Stock leaves the source at `sent` and arrives at the destination at `received`.
 * A disputed receipt keeps the original immutable and is reconciled by a
 * corrective transfer linked via parent_transfer_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('source_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('destination_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            // Corrective transfer points back at the disputed original.
            $table->foreignId('parent_transfer_id')->nullable()->constrained('inventory_transfers')->nullOnDelete();
            // Recorded when an admin pushes a transfer past the source-stock check.
            $table->foreignId('source_validation_overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['source_location_id', 'status']);
            $table->index(['destination_location_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfers');
    }
};
