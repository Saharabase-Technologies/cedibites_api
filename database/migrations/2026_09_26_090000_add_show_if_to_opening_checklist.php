<?php

use Database\Seeders\OpeningChecklistSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Questions that depend on other answers.
 *
 * "Has cover been arranged for absent staff?" is not asked once everybody has
 * reported; the low-stock list is not asked when every ingredient is there.
 * The rule sits on the checklist line and is copied onto each morning's
 * answers with the rest of the line. See App\Services\Openings\Relevance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opening_checklist_items', function (Blueprint $table) {
            $table->json('show_if')->nullable()->after('allows_na');
        });

        Schema::table('branch_opening_answers', function (Blueprint $table) {
            $table->json('show_if')->nullable()->after('allows_na');
        });

        OpeningChecklistSeeder::applyRules();
    }

    public function down(): void
    {
        Schema::table('branch_opening_answers', function (Blueprint $table) {
            $table->dropColumn('show_if');
        });

        Schema::table('opening_checklist_items', function (Blueprint $table) {
            $table->dropColumn('show_if');
        });
    }
};
