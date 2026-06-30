<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational alerts surfaced to inventory operators: negative-stock (sale outran
 * recorded stock), low-stock, near-expiry, variance, dispute, wastage, missed
 * closing. One open alert is kept per (type, item, location); it is updated in
 * place while open and resolved when the condition clears.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);                 // negative_stock, low_stock, near_expiry, …
            $table->string('severity', 16)->default('warning'); // info | warning | critical
            $table->string('status', 16)->default('open');      // open | acknowledged | resolved
            $table->foreignId('item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->string('reference_type')->nullable();  // polymorphic origin, e.g. 'order'
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('message');
            $table->json('context')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index(['type', 'item_id', 'location_id', 'status']);
            $table->index(['location_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_alerts');
    }
};
