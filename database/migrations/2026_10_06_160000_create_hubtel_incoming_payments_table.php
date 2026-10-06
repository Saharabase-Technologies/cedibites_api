<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payments customers make by dialling a branch code, and the till's checks.
     *
     * The cashier rings the sale, often as cash, and the customer then pays by
     * *713*1552# or the like. Until now nobody at the counter could see whether
     * the money arrived. Two tables let them:
     *
     * hubtel_incoming_payments holds each payment Hubtel posted and its status
     * check called Paid, never just a post somebody sent us. The till lists
     * the day's ones for its branch.
     *
     * hubtel_payment_checks holds every time a cashier typed in the transaction
     * ID from a customer's MoMo message, so the same message shown twice is
     * caught the second time.
     *
     * The post itself keeps a note of what became of it, so one that never
     * reached the till can be explained.
     */
    public function up(): void
    {
        Schema::create('hubtel_incoming_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('account_number', 32);
            // Hubtel's own reference. One row per payment, however many times
            // Hubtel posts about it.
            $table->string('client_reference', 191)->unique();
            // The ID in the customer's MoMo message.
            $table->string('network_transaction_id', 64)->nullable()->index();
            $table->string('hubtel_transaction_id', 64)->nullable();
            $table->string('payer_number', 20)->nullable();
            // What the branch received. The customer paid Hubtel's fee on top.
            $table->decimal('amount', 10, 2);
            $table->decimal('amount_charged', 10, 2)->nullable();
            $table->timestamp('paid_at');
            $table->timestamp('verified_at');
            $table->foreignId('notification_id')->nullable()->constrained('hubtel_payment_notifications')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'paid_at']);
        });

        Schema::create('hubtel_payment_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            // As the cashier typed it, from the customer's MoMo message.
            $table->string('transaction_id', 64)->index();
            // paid, ours, not_paid, not_found
            $table->string('outcome', 20);
            $table->decimal('amount', 10, 2)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::table('hubtel_payment_notifications', function (Blueprint $table) {
            // paid, ours, duplicate, failed, not_paid, not_found, unreadable
            $table->string('outcome', 20)->nullable();
            $table->timestamp('checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hubtel_payment_notifications', function (Blueprint $table) {
            $table->dropColumn(['outcome', 'checked_at']);
        });

        Schema::dropIfExists('hubtel_payment_checks');
        Schema::dropIfExists('hubtel_incoming_payments');
    }
};
