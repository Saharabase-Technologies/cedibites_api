<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A durable feedback report a human triages. Bundles the reporter's description
 * (text and/or transcribed voice), annotated screenshots with element pins, and
 * the silent-capture context (breadcrumbs, console, network, request ids) shipped
 * from the widget. `branch` is diagnostic context, not authorization — nullable,
 * nullOnDelete, so deleting a branch never destroys a report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_reports', function (Blueprint $table) {
            $table->id();
            // Human-friendly "#128" — nullable so a failed assignment never blocks
            // a report, unique so two never collide (assigned by retry-on-conflict).
            $table->unsignedInteger('number')->nullable()->unique();

            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('role_at_report')->nullable();
            $table->string('route')->nullable();
            $table->string('severity', 16)->default('annoying'); // blocking|annoying|cosmetic|suggestion

            $table->text('description')->nullable();
            $table->text('transcript')->nullable();  // filled by server-side transcription

            $table->string('audio_url')->nullable();  // voice note
            $table->string('replay_url')->nullable(); // self-hosted replay blob, if used
            $table->string('replay_id')->nullable();  // error-monitor session-replay id

            // [{url, source, pins:[{selector,label,x,y}], rects}]
            $table->json('screenshots')->nullable();
            // Mirror the client capture buffers verbatim.
            $table->json('breadcrumbs')->nullable();
            $table->json('console_entries')->nullable();
            $table->json('network_entries')->nullable();
            $table->json('request_ids')->nullable();
            $table->json('client_meta')->nullable();  // UA, viewport, build SHA, connection

            $table->string('status', 16)->default('new'); // new|triaged|in_progress|fixed|wont_fix
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'created_at']); // drives the triage inbox
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_reports');
    }
};
