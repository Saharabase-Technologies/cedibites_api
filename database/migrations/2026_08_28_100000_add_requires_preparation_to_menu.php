<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which things on the menu actually need cooking.
 *
 * A bottled drink has nothing to prepare, so routing it through New → Accepted
 * → Cooking → Ready makes the kitchen acknowledge and "cook" a Coke, and buries
 * the tickets that do need a pan.
 *
 * The category carries the default, because that is the switch somebody will
 * actually maintain — mark "Soft bites" once and every drink added to it later
 * is correct without anybody remembering. The item column is nullable and means
 * "inherit"; it exists only for the exception inside an otherwise uniform
 * category, such as a milkshake sitting among the bottled drinks.
 *
 * Both default to requiring preparation. Nothing changes until somebody
 * deliberately marks a category, which is the right way round: the failure mode
 * of a wrong default here is a drink queued in the kitchen, not a hot dish
 * skipping it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_categories', function (Blueprint $table) {
            $table->boolean('requires_preparation')
                ->default(true)
                ->after('is_active');
        });

        Schema::table('menu_items', function (Blueprint $table) {
            // NULL is not "false" — it is "no opinion, ask the category".
            $table->boolean('requires_preparation')
                ->nullable()
                ->default(null)
                ->after('is_available');
        });
    }

    public function down(): void
    {
        Schema::table('menu_categories', function (Blueprint $table) {
            $table->dropColumn('requires_preparation');
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn('requires_preparation');
        });
    }
};
