<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every payment notification Hubtel sends us, kept exactly as it came.
     *
     * Hubtel's Merchant Dashboard can now post to a URL of ours whenever a
     * payment into a branch's Collection Account succeeds or fails. That
     * includes the ones a customer starts by dialling the branch code, which
     * until now nothing on our side could see. Hubtel does not document what
     * the post looks like, so the first job is to keep it whole and read it.
     *
     * The account number comes from our own URL, one per branch, because we
     * do not yet know whether the post names the branch itself.
     */
    public function up(): void
    {
        Schema::create('hubtel_payment_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('account_number', 32)->index();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 8);
            $table->string('ip', 45)->nullable();
            $table->json('payload')->nullable();
            $table->text('raw_body')->nullable();
            $table->json('headers')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hubtel_payment_notifications');
    }
};
