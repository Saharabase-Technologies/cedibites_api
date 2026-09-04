<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a message is allowed to appear, and proof that it did.
 *
 * Two gaps closed together because they are the same question asked from both
 * ends. `expires_at` already gated when a message stops being live; nothing
 * gated when it starts, so a send was always immediate. And `delivered_at` is
 * stamped by the dispatcher, which records that a row was written, not that
 * anything reached a screen. On a release nobody opens from the bell, that left
 * `acknowledged_at` as the only evidence, so a walkthrough that appeared and was
 * walked away from was indistinguishable from one that never appeared at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_messages', function (Blueprint $table) {
            // Nothing shows before this. Null means no floor, which is what
            // every existing row wants, so the default preserves them exactly.
            //
            // Separate from `sent_at` on purpose. Sending is the act of
            // resolving an audience and writing the receipts, and it has to
            // happen for the message to exist at all. When staff first see it is
            // a different decision, and collapsing the two would mean a release
            // written on Friday could not be held until Monday without leaving
            // it a draft over the weekend.
            $table->timestamp('visible_from')->nullable()->after('expires_at')->index();

            // Which moment is allowed to put it on screen. See
            // App\Enums\StaffMessageTrigger. Interpreted by the client, because
            // every one of these is a fact only the browser knows: whether this
            // page load is newer than the send, whether the window just regained
            // focus. The server's job is the `visible_from` floor.
            $table->string('display_trigger')->default('immediate')->after('visible_from');
        });

        Schema::table('staff_message_recipients', function (Blueprint $table) {
            // First time it actually took this person's screen.
            $table->timestamp('shown_at')->nullable()->after('delivered_at');

            // Most recent time. A release keeps interrupting until it is
            // acknowledged, so first and last are different questions: one says
            // whether it ever reached them, the other whether it is still
            // reaching them and being dismissed.
            $table->timestamp('last_shown_at')->nullable()->after('shown_at');

            // How many times it has taken their screen. "Seen it four times and
            // still not acknowledged" is a different conversation from "has not
            // seen it once", and neither timestamp alone separates them.
            $table->unsignedInteger('shown_count')->default(0)->after('last_shown_at');
        });
    }

    public function down(): void
    {
        Schema::table('staff_messages', function (Blueprint $table) {
            $table->dropIndex(['visible_from']);
            $table->dropColumn(['visible_from', 'display_trigger']);
        });

        Schema::table('staff_message_recipients', function (Blueprint $table) {
            $table->dropColumn(['shown_at', 'last_shown_at', 'shown_count']);
        });
    }
};
