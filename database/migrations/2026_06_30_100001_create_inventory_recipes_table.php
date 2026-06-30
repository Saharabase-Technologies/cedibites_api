<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recipes / BOM. One recipe per menu-item option (size/variant), optionally
 * overridden per branch (branch_id null = global default). Ingredients live in
 * inventory_recipe_ingredients. Drives automatic stock deduction on sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_option_id')->constrained('menu_item_options')->cascadeOnDelete();
            // Null = global default; a branch id = per-branch override.
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->boolean('is_default')->default(true);
            $table->enum('status', ['draft', 'observation', 'locked'])->default('draft');
            $table->unsignedInteger('version')->default(1);
            // Portions this BOM produces (ingredient qty is per yield_qty portions).
            $table->decimal('yield_qty', 14, 4)->default(1);
            $table->foreignId('locked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['menu_item_option_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_recipes');
    }
};
