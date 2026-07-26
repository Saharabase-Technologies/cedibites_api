<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wastage — the NAMED half of every loss.
 *
 * Stock that leaves without being sold goes out one of two doors: the wastage
 * door, where someone says what happened, or the variance door, where nobody
 * knows. This table is the first door. A claim is never a bare number: it
 * carries a reason per line, a value, an approver where the value warrants one,
 * and — above the threshold at a branch — the physical journey of the goods back
 * to the warehouse that supplied them.
 *
 * `location_id` is where the loss originated and who answers for it.
 * `disposal_location_id` is where the write-off movement actually posts, which
 * differs only when the goods were returned: the branch's stock left on the
 * return transfer, so the warehouse is what gets written down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_wastages', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 24)->unique();  // WST-YYMMDD-NNN

            // Who owns the loss, and where it is finally written off.
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('disposal_location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();

            $table->string('origin', 24)->default('manual');   // WastageOrigin
            $table->string('status', 24)->default('approved'); // WastageStatus

            // Valuation at declaration time (weighted average cost).
            $table->decimal('total_value', 16, 4)->default(0);
            $table->decimal('threshold_amount', 14, 2)->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->boolean('requires_return')->default(false);

            // The branch → warehouse transfer carrying the goods back for
            // inspection. Set only when requires_return is true.
            $table->unsignedBigInteger('return_transfer_id')->nullable();

            // The document this claim was raised from: a daily closing, a
            // transfer, a reconciliation cycle. Null for a plain declaration.
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['location_id', 'status']);
            $table->index(['status', 'origin']);
            $table->index(['source_type', 'source_id']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_wastages');
    }
};
