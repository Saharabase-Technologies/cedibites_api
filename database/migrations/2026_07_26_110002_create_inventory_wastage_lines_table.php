<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line per item-and-reason. The same item may appear twice in a declaration
 * when two different things happened to it (2 kg burnt, 1 kg spilled), so there
 * is deliberately no unique constraint on (wastage, item) — collapsing them
 * would throw away exactly the detail the report exists to show.
 *
 * `movement_id` is the `wastage` movement this line posted, and is null both
 * before approval and forever on classification-only origins, where the ledger
 * was already corrected elsewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_wastage_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wastage_id')->constrained('inventory_wastages')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('inventory_units')->restrictOnDelete();

            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('line_value', 16, 4)->default(0);

            $table->string('reason', 32);                  // WastageReason
            $table->text('reason_note')->nullable();       // required when reason = other

            $table->unsignedBigInteger('movement_id')->nullable();

            $table->timestamps();

            $table->index('item_id');
            $table->index(['wastage_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_wastage_lines');
    }
};
