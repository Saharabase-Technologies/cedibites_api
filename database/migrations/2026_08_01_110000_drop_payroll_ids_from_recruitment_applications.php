<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The recruitment form stops asking for SSNIT and TIN.
 *
 * An applicant is not on payroll and frequently has neither number yet, so the
 * questions cost goodwill on a public form and bought nothing. Both columns stay
 * on `employees`, where the staff editor fills them in once somebody is actually
 * hired.
 *
 * A follow-up rather than an edit to the create migration: that one has already
 * shipped, and rewriting a migration that may have run leaves the schema
 * disagreeing with the file that claims to describe it. The `hasColumn` guards
 * make this correct either way — on an environment where the first migration
 * never ran with these columns, it is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_applications', function (Blueprint $table) {
            foreach (['ssnit_number', 'tin_number'] as $column) {
                if (Schema::hasColumn('recruitment_applications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('recruitment_applications', 'ssnit_number')) {
                $table->text('ssnit_number')->nullable();
            }

            if (! Schema::hasColumn('recruitment_applications', 'tin_number')) {
                $table->text('tin_number')->nullable();
            }
        });
    }
};
