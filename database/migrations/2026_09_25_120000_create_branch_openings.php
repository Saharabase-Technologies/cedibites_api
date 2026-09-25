<?php

use Database\Seeders\OpeningChecklistSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The daily opening checklist.
 *
 * `opening_checklist_items` is the checklist as head office wants it today.
 * Each morning's opening copies it into `branch_opening_answers`, label and
 * all, so changing the checklist later never rewrites what a manager was
 * asked and answered on an earlier day. The same rule `order_items` follows
 * for prices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('section', 60);
            $table->string('group', 60)->nullable();
            $table->string('label', 255);
            // What the alert texts call it: "gas", not "Gas supply checked and adequate."
            $table->string('short', 60);
            $table->string('help', 255)->nullable();
            // check | number | text
            $table->string('kind', 10)->default('check');
            // must_pass | can_open | record
            $table->string('weight', 12)->default('can_open');
            $table->boolean('allows_na')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branch_openings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            // The IMS business day, which ends at 03:00, not midnight.
            $table->date('business_date');

            $table->timestamp('started_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            // When selling began, which is not always when the checklist was
            // finished: head office can open a branch before its manager has.
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('opened_via', 10)->nullable(); // pos | portal | admin
            $table->boolean('is_override')->default(false);
            $table->text('override_reason')->nullable();

            $table->text('unresolved_note')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('problems_resolved_at')->nullable();

            // What head office has been told, so nobody is told twice.
            $table->timestamp('late_alerted_at')->nullable();
            $table->timestamp('late_reminded_at')->nullable();
            $table->timestamp('grace_alerted_at')->nullable();
            $table->timestamp('last_reminded_at')->nullable();

            $table->timestamps();

            $table->unique(['branch_id', 'business_date']);
            $table->index('business_date');
        });

        Schema::create('branch_opening_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_opening_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->nullable()->constrained('opening_checklist_items')->nullOnDelete();

            // A copy of the item as it read that morning.
            $table->string('key', 60);
            $table->string('section', 60);
            $table->string('group', 60)->nullable();
            $table->string('label', 255);
            $table->string('short', 60);
            $table->string('help', 255)->nullable();
            $table->string('kind', 10);
            $table->string('weight', 12);
            $table->boolean('allows_na')->default(false);
            $table->unsignedSmallInteger('position')->default(0);

            // ok | problem | na, for a check. A number or text goes in `value`.
            $table->string('answer', 10)->nullable();
            $table->text('value')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();

            $table->timestamps();

            $table->unique(['branch_opening_id', 'key']);
        });

        Schema::create('branch_opening_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_opening_answer_id')->constrained()->cascadeOnDelete();
            // reported (the problem) or fixed (the fix), decided by the server.
            $table->string('stage', 10);
            $table->string('path');
            $table->string('url');
            $table->string('thumb_url')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('branches', function (Blueprint $table) {
            // Off for every branch until head office switches it on, so
            // nothing changes for a branch the day this deploys.
            $table->boolean('requires_opening_checklist')->default(false)->after('extended_order_access');
        });

        (new OpeningChecklistSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('requires_opening_checklist');
        });

        Schema::dropIfExists('branch_opening_photos');
        Schema::dropIfExists('branch_opening_answers');
        Schema::dropIfExists('branch_openings');
        Schema::dropIfExists('opening_checklist_items');
    }
};
