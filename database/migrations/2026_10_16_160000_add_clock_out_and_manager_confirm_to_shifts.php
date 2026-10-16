<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clock out and the two sign-offs (staff portal D29, D30).
 *
 * At Clock out the person confirms the money they took, and what they saw is
 * kept here as it was at the button. A payment linked to the shift later does
 * not move it. The manager then confirms the shift, with a note if needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->timestamp('clocked_out_at')->nullable();
            // The money by method, No Charge and the money taken on other
            // people's orders, as the Clock out sheet showed them.
            $table->json('clock_out_money')->nullable();
            // The person's orders still owing, as the sheet showed them.
            $table->json('clock_out_owing')->nullable();
            $table->text('clock_out_note')->nullable();

            $table->foreignId('manager_confirmed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('manager_confirmed_at')->nullable();
            $table->text('manager_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_confirmed_by');
            $table->dropColumn([
                'clocked_out_at', 'clock_out_money', 'clock_out_owing', 'clock_out_note',
                'manager_confirmed_at', 'manager_note',
            ]);
        });
    }
};
