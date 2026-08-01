<?php

use App\Enums\RecruitmentLinkKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per recruitment posting you send out.
 *
 * The link carries the posting, so the recruit never chooses a branch and
 * nobody has to sort applicants by hand afterwards. Its kind decides whether a
 * branch is stamped at all — see App\Enums\RecruitmentLinkKind for why the call
 * centre needs its own kind rather than a branch of "none".
 *
 * There is no close button and no headcount cap by decision; expires_at is the
 * only thing that shuts a link, which is why it is not nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_links', function (Blueprint $table) {
            $table->id();

            // The bearer credential in the URL. Random, not sequential: order
            // numbers are guessable and that is already a known exposure (see
            // the guest order-tracking comment in routes/public.php). Do not
            // repeat it here.
            $table->string('token', 64)->unique();

            $table->string('kind', 32);

            // Null for a call-centre link, and that null is load-bearing —
            // it becomes an empty employee_branch set, which is what
            // User::isCompanyWide() expects to find.
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('created_by_user_id')->constrained('users');

            // Human label so a list of links reads as postings rather than
            // hashes — "Lakeside November intake".
            $table->string('label')->nullable();

            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['kind', 'expires_at']);
            $table->index('branch_id');
        });

        // A branch link without a branch stamps nothing; a call-centre link with
        // one stamps the very row that breaks the call centre. Neither is a state
        // the application should be able to reach, so the database refuses it too.
        // Postgres only — SQLite cannot add a check constraint after create, and
        // the request layer covers the test path.
        if (DB::getDriverName() === 'pgsql') {
            $branch = RecruitmentLinkKind::Branch->value;
            $callCenter = RecruitmentLinkKind::CallCenter->value;

            DB::statement("
                ALTER TABLE recruitment_links
                ADD CONSTRAINT recruitment_links_branch_matches_kind
                CHECK (
                    (kind = '{$branch}' AND branch_id IS NOT NULL)
                    OR (kind = '{$callCenter}' AND branch_id IS NULL)
                )
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_links');
    }
};
