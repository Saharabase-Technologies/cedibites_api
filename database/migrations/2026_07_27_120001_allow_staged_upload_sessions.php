<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upload sessions that hold files for a document which does not exist yet.
 *
 * The gap this closes: an upload session was scoped to ONE existing document,
 * so a phone could not photograph anything while a form was still being filled
 * in. "Save and use phone" therefore had to save the claim first - which closed
 * the record-wastage form, and with it any chance to write the notes or add the
 * second item. The photograph is taken at the crate, before anybody has finished
 * typing; the software had it the wrong way round.
 *
 * A STAGED session has no `attachable` yet. Files land in `upload_session_files`
 * and sit there, visible to the form that minted the session, until the document
 * is finally created - at which point the session is CLAIMED and the files are
 * attached for real.
 *
 * Two consequences worth being explicit about:
 *
 *   Staged files are not evidence yet. Nothing references them, no `stage` has
 *   been derived, and if the form is abandoned they are orphans - which is why
 *   they carry an expiry and get pruned.
 *
 *   A staged session is still scoped to its creator and still upload-only. It
 *   grants no read access to anything; the phone can put files in and cannot
 *   list them back out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            // Null until claimed. The pair was previously mandatory, which is
            // exactly what forced a document to exist before a photo could.
            $table->string('attachable_type')->nullable()->change();
            $table->unsignedBigInteger('attachable_id')->nullable()->change();
        });

        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->timestamp('claimed_at')->nullable()->after('consumed_at');
        });

        Schema::create('upload_session_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_session_id')
                ->constrained('upload_sessions')
                // A session going means its unclaimed files were never evidence.
                ->cascadeOnDelete();

            $table->string('path');
            $table->string('url');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->string('caption')->nullable();

            // Set once the files have been attached to the real document, so a
            // double submit cannot attach the same photo twice.
            $table->timestamp('attached_at')->nullable();

            $table->timestamps();

            $table->index(['upload_session_id', 'attached_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_session_files');

        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->dropColumn('claimed_at');
        });
    }
};
