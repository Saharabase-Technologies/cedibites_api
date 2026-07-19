<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-line stock request raised by a branch against the warehouse (the "request
 * layer" that sits in front of a physical transfer). On approval the warehouse
 * manager sets the granted quantities and a fulfilling transfer is spawned; the
 * requisition flips to `fulfilled` once that transfer is received.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            // Who needs the stock (a satellite branch).
            $table->foreignId('requesting_location_id')->constrained('inventory_locations')->restrictOnDelete();
            // Where it is pulled from. MVP is warehouse → branch; kept flexible.
            $table->string('source_type', 16)->default('warehouse');
            $table->foreignId('source_location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->string('purpose', 16)->default('supplementary'); // opening | supplementary
            $table->string('status', 24)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            // The transfer spawned to fulfil this requisition (approve → transfer).
            $table->foreignId('fulfilling_transfer_id')->nullable()->constrained('inventory_transfers')->nullOnDelete();
            $table->string('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['requesting_location_id', 'status']);
            $table->index(['source_location_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_requisitions');
    }
};
