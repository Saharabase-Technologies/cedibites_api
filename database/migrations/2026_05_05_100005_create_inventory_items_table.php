<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();

            $table->foreignId('category_id')->nullable()->constrained('inventory_categories')->nullOnDelete();
            $table->foreignId('base_unit_id')->constrained('inventory_units')->restrictOnDelete();
            $table->foreignId('default_supplier_id')->nullable()->constrained('inventory_suppliers')->nullOnDelete();

            $table->enum('storage_type', ['dry', 'cold', 'frozen', 'ambient'])->default('dry');
            $table->boolean('is_consumable')->default(true);
            $table->boolean('expiry_tracked')->default(false);

            $table->decimal('reorder_level', 12, 3)->nullable();
            $table->decimal('min_threshold', 12, 3)->nullable();
            $table->decimal('weighted_avg_cost', 12, 4)->default(0);

            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'is_active']);
            $table->index(['storage_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
