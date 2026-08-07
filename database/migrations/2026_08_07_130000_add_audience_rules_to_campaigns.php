<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audience an operator assembled, rather than one of the six presets.
 *
 * Nullable, and null is the normal case: a campaign that picked a preset stores
 * only its `segment`. When rules are present they take over, and `segment` stays
 * as the label of the preset the operator started from.
 *
 * Stored as JSON on the campaign rather than resolved to a list of phone numbers
 * for two reasons. A stored list would be a snapshot of who was in the audience
 * when the draft was written, which is not who should receive it a week later.
 * And it would be a copy of thousands of customer phone numbers per campaign,
 * sitting in a table nobody thinks of as holding personal data.
 *
 * Keeping the rules means a campaign can be read back a year later as the
 * question it asked, and re-resolved against the answer today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->json('audience_rules')->nullable()->after('segment');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('audience_rules');
        });
    }
};
