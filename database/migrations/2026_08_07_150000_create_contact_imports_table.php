<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per uploaded contact list — the provenance of every imported number.
 *
 * A batch exists so that "where did this number come from?" always has an
 * answer. A list bought, collected at an event, or copied out of a church
 * directory carries wildly different expectations about being texted, and six
 * months later nobody remembers which was which. The label and the filename are
 * that memory.
 *
 * It is also what makes an import undoable. Deleting 4,000 rows one at a time is
 * not a recovery path; deleting the batch is. The counts are kept on this row
 * rather than derived, because after an undo the contacts are gone and the
 * record of what the file contained should not go with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_imports', function (Blueprint $table) {
            $table->id();

            // What the operator called this list — "Accra Mall activation,
            // August". Free text, and the thing actually shown in the UI.
            $table->string('label');

            $table->string('filename')->nullable();

            // Where the list came from, in the operator's own words. Kept
            // because it is the only field that can answer the consent question
            // when the compliance team eventually asks it.
            $table->text('source_note')->nullable();

            $table->foreignId('uploaded_by_user_id')->constrained('users');

            // The parse breakdown, frozen at commit time. `imported` is what
            // became rows; the other three explain the difference between that
            // and the row count of the file, which is the first question anybody
            // asks when 4,000 lines become 3,612 contacts.
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);

            // How many of the imported numbers we already sell to. Counted apart
            // from the rest because it is the figure that stops an import being
            // read as growth — buying a list of 4,000 that turns out to be 3,900
            // existing customers is worth knowing on the day, not a quarter
            // later.
            $table->unsignedInteger('already_customer_count')->default(0);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_imports');
    }
};
