<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photo evidence on a wastage claim.
 *
 * Straight from the client walkthrough: *"So show me the food that has gone
 * bad."* A branch claiming goods arrived spoiled and a warehouse insisting they
 * left fine is a conflict no quantity can settle — somebody has to look at the
 * food. This is where they look.
 *
 * `stage` is what makes it work as evidence rather than decoration:
 *   declared   — the claimant's photos, taken when the loss was raised.
 *   inspection — the approver's photos, taken with the returned goods in front
 *                of them. This is the counter-evidence side of the argument.
 *
 * Both sides stay on the record permanently, and both are visible to both ends,
 * because the wastage itself is scoped to origin AND disposal location. Neither
 * party can quietly remove the other's photos, and nobody can alter anything
 * once the claim is settled.
 *
 * Files live on the `public` disk under `inventory/wastage/{id}/`, the same
 * storage the feedback module already uses. `path` is the canonical value —
 * `url` is a convenience for rendering and would be wrong if the disk moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_wastage_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wastage_id')->constrained('inventory_wastages')->cascadeOnDelete();

            $table->string('stage', 16)->default('declared'); // declared | inspection
            $table->string('path');
            $table->string('url');
            $table->string('caption')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['wastage_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_wastage_photos');
    }
};
