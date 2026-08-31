<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "What's new" — a message that is paged through rather than read at once.
 *
 * A release note is a poor fit for a single body of markdown. It covers several
 * unrelated changes, each of which usually wants its own screenshot, and asking
 * somebody to absorb five of them from one wall of text is how a release note
 * goes unread. One row per slide, ordered, each with its own picture.
 *
 * `release_key` is the guard against sending the same release twice. Announcing
 * the same changes a second time trains people to dismiss these unread, and on a
 * kind that interrupts every login until acknowledged, a duplicate is not a
 * cosmetic problem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_message_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_message_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title', 150)->nullable();
            $table->text('body');
            // A path this API issued from the upload endpoint, never a URL the
            // caller chose — same rule as the message's own image.
            $table->string('image_path')->nullable();
            $table->timestamps();

            $table->unique(['staff_message_id', 'position']);
        });

        Schema::table('staff_messages', function (Blueprint $table) {
            $table->string('release_key', 100)->nullable()->unique()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('staff_messages', function (Blueprint $table) {
            $table->dropColumn('release_key');
        });

        Schema::dropIfExists('staff_message_steps');
    }
};
