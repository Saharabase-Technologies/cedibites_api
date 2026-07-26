<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone-as-camera upload sessions.
 *
 * The problem this solves: everyone in the IMS works on a laptop, and nobody is
 * going to carry a laptop to a crate of spoiled chicken on the floor. So the
 * desktop shows a QR code, the phone scans it, and a no-login page opens that
 * can attach photos and video to exactly one document.
 *
 * Deliberately NOT `inventory_`-prefixed and deliberately polymorphic: deliveries
 * and daily counts have the same problem, and bolting this to wastage would only
 * mean rewriting it later.
 *
 * ── The security shape ───────────────────────────────────────────────────────
 * The token is a bearer credential inside a screenshot-able square. Anyone who
 * photographs the laptop screen holds it. Every column below exists to make that
 * survivable:
 *
 *   token_hash    — the raw token is shown ONCE, in the QR, and never stored. A
 *                   database leak yields nothing usable.
 *   attachable_*  — scoped to ONE document. Not "wastage claims", one claim.
 *   created_by    — the phone acts AS this person. This is not decoration:
 *                   WastageService::attachPhoto() derives `stage` (declared vs
 *                   inspection) from the actor, so an anonymous upload would
 *                   silently file the branch's evidence under the approver's
 *                   name. See the wastage photos migration.
 *   expires_at    — minutes, not hours.
 *   max_files     — a stolen token cannot be used to fill the disk.
 *   consumed_at / revoked_at — settling the target kills the token; so does a
 *                   human deciding the screen was seen by the wrong person.
 *   last_ip / last_user_agent — every use is attributable after the fact.
 *
 * The capability is upload-only. The public read endpoint returns a reference
 * and a one-line label and nothing else — never document contents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table) {
            $table->id();

            // SHA-256 hex. Unique so a lookup is a single indexed equality
            // check — we cannot scan-and-verify like a password.
            $table->string('token_hash', 64)->unique();

            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');

            // The phone acts as this user. Cascades: a deleted user's token
            // has no actor to act as, so it must not survive them.
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->string('purpose', 64); // e.g. wastage_evidence

            $table->unsignedSmallInteger('max_files')->default(10);
            $table->unsignedSmallInteger('files_uploaded')->default(0);

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->string('last_ip', 45)->nullable(); // IPv6-sized
            $table->text('last_user_agent')->nullable();

            $table->timestamps();

            // "Is there already a live session for this document?" — asked on
            // every QR press, so the desktop reuses rather than minting a
            // second token for the same crate of food.
            $table->index(['attachable_type', 'attachable_id']);
            $table->index('expires_at'); // pruning
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
    }
};
