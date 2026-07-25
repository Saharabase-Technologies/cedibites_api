<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-page notes on a feedback report.
 *
 * One report can roam several pages before it is submitted, and each page
 * usually needs its own words — "the branch picker is wrong here", "this total
 * is off there". A single description and a single voice note forced the
 * reporter to flatten all of that into one blob. A note is text, a voice clip,
 * or both, pinned to the route it was recorded on.
 *
 * The report's own `description` / `audio_url` stay as the overall summary, so
 * existing reports keep reading exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_report_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_report_id')->constrained()->cascadeOnDelete();

            // The page this note is about. Nullable — a note made before any
            // navigation, or one deliberately about the session as a whole.
            $table->string('route')->nullable();
            $table->string('page_title')->nullable();

            $table->text('body')->nullable();
            $table->string('audio_url')->nullable();
            $table->text('transcript')->nullable(); // filled by transcription

            // Authoring order, so the triage view replays the reporter's train
            // of thought rather than an arbitrary id order.
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->index(['feedback_report_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_report_notes');
    }
};
