<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a dish is served — the expand step of the menu unification
 * (docs/BRANCH_ISOLATION_PLAN.md, Phase 3).
 *
 * `menu_items` carries a branch_id today, so every branch holds its own
 * duplicate of the same dish with its own id. That is why recipes stop working
 * at a second branch (they key on menu_item_option_id, and each branch's option
 * ids are different), why a promo only ever lands at one branch, and why a new
 * branch shows every dish as unrated.
 *
 * After `menu:unify` there is one row per dish and this table says which
 * branches serve it. Price stays in menu_item_option_branch_prices, which
 * already exists.
 *
 * Structural only — creates an empty table and changes no behaviour. The data
 * merge is a separate, reviewable command.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            // The branch manager's one menu power: sold out for today. Distinct
            // from menu_items.is_available, which takes a dish off every branch.
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->unique(['menu_item_id', 'branch_id']);
            $table->index(['branch_id', 'is_available']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_branches');
    }
};
