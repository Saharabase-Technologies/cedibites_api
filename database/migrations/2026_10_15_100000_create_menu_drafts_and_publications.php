<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menu changes that wait, and the record of each time they went live.
     *
     * The owner asked for new prices to be typed in ahead of time and switched
     * on at a moment of their choosing. Until then the till, the website and
     * the app keep charging today's prices, because nothing here is read by
     * anything that sells.
     *
     * A new option waits here too, never as a hidden row in
     * `menu_item_options`. The API returns every option whether it is on sale
     * or not, and the screens that sell do not filter by `is_available`, so a
     * hidden row would be on sale the moment it was created.
     */
    public function up(): void
    {
        Schema::create('menu_drafts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();

            // Null for a change to the dish itself: a rename, taking it off
            // sale, or an option that does not exist yet.
            $table->foreignId('menu_item_option_id')->nullable()
                ->constrained('menu_item_options')->cascadeOnDelete();

            // See MenuDraft::FIELDS.
            $table->string('field', 32);

            // A price, a name, true, or a whole new option. Json so each field
            // keeps its own type.
            $table->json('value');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['menu_item_id', 'menu_item_option_id', 'field']);
        });

        Schema::create('menu_publications', function (Blueprint $table) {
            $table->id();

            // See MenuPublicationStatus.
            $table->string('status', 16)->index();

            // When a scheduled publish is due. Server time.
            $table->timestamp('publish_at')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('undone_at')->nullable();

            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            // Null when the scheduler pressed it. Then scheduled_by is who asked.
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('change_count')->default(0);

            // Why a scheduled publish did not happen, or what an undo had to skip.
            $table->string('note')->nullable();

            $table->timestamps();
        });

        Schema::create('menu_publication_changes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('menu_publication_id')->constrained()->cascadeOnDelete();

            // Dishes and options are soft deleted, so these hold. nullOnDelete
            // only so a hard delete of a test dish cannot be blocked by history.
            $table->foreignId('menu_item_id')->nullable()->constrained('menu_items')->nullOnDelete();
            $table->foreignId('menu_item_option_id')->nullable()
                ->constrained('menu_item_options')->nullOnDelete();

            $table->string('field', 32);
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();

            // The line's name when it changed, so history still reads right
            // after a rename.
            $table->string('line_name');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_publication_changes');
        Schema::dropIfExists('menu_publications');
        Schema::dropIfExists('menu_drafts');
    }
};
