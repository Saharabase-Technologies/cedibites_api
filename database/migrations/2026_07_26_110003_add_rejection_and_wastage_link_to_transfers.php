<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two additions to transfers.
 *
 * REJECTION. Until now the receiving end could only accept — a short count filed
 * a dispute, but there was no way to refuse a consignment outright. That gap
 * matters because it decides who carries a loss: goods refused at the door go
 * straight back and stay the sender's problem, while goods signed for become the
 * receiver's to declare as wastage. `reject_reason_code` uses the same
 * WastageReason vocabulary as every other loss so the reports reconcile.
 *
 * WASTAGE RETURN LEG. Above the value threshold a branch cannot simply write
 * stock off — the goods must physically go back to the warehouse that supplied
 * them, so the manager who answers for them can look at them. `wastage_id` marks
 * a transfer as that return journey; receiving it advances the claim to
 * awaiting-approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->foreignId('rejected_by')->nullable()->after('received_by')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('received_at');
            $table->text('reject_reason')->nullable()->after('cancel_reason');
            $table->string('reject_reason_code', 32)->nullable()->after('reject_reason');

            $table->unsignedBigInteger('wastage_id')->nullable()->after('requisition_id');
            $table->index('wastage_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropIndex(['wastage_id']);
            $table->dropColumn(['rejected_at', 'reject_reason', 'reject_reason_code', 'wastage_id']);
        });
    }
};
