<?php

use App\Enums\RecruitmentApplicationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A filled-in recruitment form, before anyone has decided anything.
 *
 * This is deliberately not a user and not an employee. Nothing here can log in.
 * The account is created on approval, by EmployeeProvisioningService, from these
 * fields plus the role the reviewer picks and the branch the link carries.
 *
 * Columns mirror CreateEmployeeRequest so approval is a straight handover; if a
 * field is added to hiring, it belongs here too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_applications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('recruitment_link_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // Stored normalised (User::normalizePhone) and indexed, because every
            // duplicate check here compares against users.phone, which is also
            // normalised. `024…` and `+233…` are the same phone to everyone
            // except a string comparison.
            $table->string('phone', 32)->index();
            $table->string('email')->nullable();

            // The applicant chose it; we hold only the hash. It is never written
            // to users.recoverable_password on approval — that vault exists for
            // passwords an admin generated and has to pass on, and an admin has
            // no business reading one the applicant picked.
            $table->string('password_hash');

            // HR information — same encrypted casts as employees.
            $table->text('ssnit_number')->nullable();
            $table->text('ghana_card_id')->nullable();
            $table->text('tin_number')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('nationality')->nullable();

            // Emergency contact.
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 32)->nullable();
            $table->string('emergency_contact_relationship')->nullable();

            $table->string('status', 16)->default(RecruitmentApplicationStatus::Pending->value);

            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            // The audit trail from application to account. Null while pending,
            // null forever if rejected.
            $table->foreignId('created_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The review queue: pending first, newest first.
            $table->index(['status', 'created_at']);
            $table->index(['recruitment_link_id', 'status']);
        });

        // One *open* application per person per posting, so a double-tap on
        // submit cannot produce two rows to review.
        //
        // It has to be partial. A plain unique on (link, phone, status) would
        // also forbid the second rejected row, so someone rejected once could
        // never apply to that posting again — and the failure would surface as a
        // 500 on the form, not as a rule anyone could see. Postgres and SQLite
        // both take a partial unique index; MySQL would not, and this app does
        // not run on it.
        $pending = RecruitmentApplicationStatus::Pending->value;

        DB::statement("
            CREATE UNIQUE INDEX recruitment_applications_one_open_per_link
            ON recruitment_applications (recruitment_link_id, phone)
            WHERE status = '{$pending}'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_applications');
    }
};
