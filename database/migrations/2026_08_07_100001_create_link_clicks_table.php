<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per tap, so click-through can be read over time rather than as a
 * single running total.
 *
 * Deliberately disposable: no timestamps beyond `clicked_at`, and pruned on a
 * schedule (links:prune-clicks). The number that must survive pruning is
 * `short_links.click_count`, which is incremented alongside and never trimmed —
 * so a campaign report shown to the board last month returns the same figure
 * this month.
 *
 * No IP address is stored. It would add nothing we report on and would turn a
 * click log into personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_clicks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('short_link_id')->constrained()->cascadeOnDelete();

            $table->timestamp('clicked_at');

            // Forwarded by the Next.js route handler from the customer's own
            // request. Taken from the handler rather than the incoming request
            // because the only caller of the resolve endpoint is our own server,
            // whose user agent would otherwise be recorded 28,000 times.
            $table->string('user_agent', 500)->nullable();
            $table->string('referer', 500)->nullable();

            $table->index(['short_link_id', 'clicked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_clicks');
    }
};
