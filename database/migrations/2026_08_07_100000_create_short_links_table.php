<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per short link we hand out.
 *
 * This table exists because SMS is billed in 160-character steps, not per
 * character. A campaign URL of 77 characters is what pushes an otherwise
 * one-segment message into two, doubling the cost of the entire send. Shortening
 * it saves nothing at 100 characters and saves half the bill at 161.
 *
 * The token is stored; the URL is not. Links are built from a configurable base
 * (config/short_links.php), so moving to a shorter domain later is one
 * environment variable rather than a rebuild — and every link already sitting in
 * somebody's inbox keeps resolving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('short_links', function (Blueprint $table) {
            $table->id();

            // Base62, random, never sequential. Sequential ids would let anyone
            // walk every link we have ever created — reading the unreleased
            // campaign calendar in one script. Six characters is the default;
            // the column is wider so the length can be raised without a
            // migration.
            $table->string('token', 16)->unique();

            // What it is, so the admin list reads as campaigns rather than
            // hashes — "August Friday jollof promo".
            $table->string('label');

            $table->text('target_url');

            $table->foreignId('created_by_user_id')->constrained('users');

            // Denormalised so listing a hundred links does not count a hundred
            // thousand click rows. link_clicks stays the detail, and is pruned;
            // this total is not.
            $table->unsignedInteger('click_count')->default(0);

            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_links');
    }
};
