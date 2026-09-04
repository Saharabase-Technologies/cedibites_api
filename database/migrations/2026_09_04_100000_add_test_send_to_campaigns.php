<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who sent themselves a copy of this campaign, and when.
 *
 * A test send is a real text to a real handset. It costs about two pesewas and
 * it is the only way to see the message as a customer will, short link and all,
 * before 28,000 people get it. Somebody has to be able to answer "was this one
 * read on a phone before it went out" afterwards, and a claim in a meeting is
 * not an answer.
 *
 * Only the latest test is kept. The activity log holds every one of them with
 * the full text; these three columns exist so the campaign screen can say
 * "Tested to 0241234567 at 3:41pm by Richard Somda" without reading the log.
 *
 * Deliberately separate from every counter on this row. A test writes nothing to
 * sent_count, failed_count, estimated_cost or batch_ids, because a campaign that
 * has been tested is still a campaign that has not been sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->timestamp('last_tested_at')->nullable()->after('completed_at');

            // Stored as we store every other number, +233XXXXXXXXX. The Hubtel
            // form is a boundary detail and does not belong in a column somebody
            // will read off the screen.
            $table->string('last_tested_phone', 20)->nullable()->after('last_tested_at');

            $table->foreignId('last_tested_by_user_id')
                ->nullable()
                ->after('last_tested_phone')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_tested_by_user_id');
            $table->dropColumn(['last_tested_at', 'last_tested_phone']);
        });
    }
};
