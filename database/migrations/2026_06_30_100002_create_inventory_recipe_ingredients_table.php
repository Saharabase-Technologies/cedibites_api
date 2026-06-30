<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One ingredient line of a recipe: an inventory item + quantity (in the given
 * unit) consumed per yield_qty portions of the recipe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('inventory_recipes')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('inventory_units')->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->timestamps();

            $table->index('recipe_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_recipe_ingredients');
    }
};
