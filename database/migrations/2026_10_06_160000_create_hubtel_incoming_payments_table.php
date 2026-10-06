<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payments a customer made to a branch without the till asking for them.
     *
     * Mostly the branch code, *713*1552# and the like. Each row is a payment
     * Hubtel's status check has called Paid, never just a post somebody sent
     * us. The cashier picks one to settle a sale, and from then on it belongs
     * to that order and cannot settle another.
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
            // What the branch received, which is what the sale is worth. The
            // customer paid Hubtel's fee on top of it.
            $table->decimal('amount', 10, 2);
            $table->decimal('amount_charged', 10, 2)->nullable();
            $table->timestamp('paid_at');
            $table->timestamp('verified_at');
            $table->foreignId('notification_id')->nullable()->constrained('hubtel_payment_notifications')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('claimed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'paid_at']);
        });

        Schema::table('hubtel_payment_notifications', function (Blueprint $table) {
            // paid, ours, duplicate, failed, not_paid, not_found, no_key, unreadable
            $table->string('outcome', 20)->nullable();
            $table->timestamp('checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hubtel_payment_notifications', function (Blueprint $table) {
            $table->dropColumn(['outcome', 'checked_at']);
        });

        Schema::dropIfExists('hubtel_incoming_payments');
    }
};
