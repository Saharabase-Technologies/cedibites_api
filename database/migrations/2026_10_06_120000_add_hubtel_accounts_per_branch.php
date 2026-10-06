<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each branch collects into its own Hubtel account.
     *
     * Hubtel gives every branch a Collection Account of its own (Ashaiman
     * 2038092, Lakeside 2040195, East Legon 2040749), but the app only ever
     * knew the one in the environment, so every branch's money landed in
     * Ashaiman's. A branch now carries its own account number and payment key.
     * A branch without them keeps using the environment's account, which is
     * how all of them worked until now.
     *
     * The payment rows remember which account they were sent to. A status
     * check only looks inside one account, so a payment started on Ashaiman's
     * account the morning Lakeside moves over has to be asked about there, not
     * in Lakeside's.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('hubtel_account_number', 32)->nullable()->unique();
            $table->string('hubtel_api_id', 64)->nullable();
            // Encrypted with APP_KEY by the model cast, so it is text, not a short string.
            $table->text('hubtel_api_key')->nullable();
        });

        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->string('hubtel_account_number', 32)->nullable();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('hubtel_account_number', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('hubtel_account_number');
        });

        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->dropColumn('hubtel_account_number');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique(['hubtel_account_number']);
            $table->dropColumn(['hubtel_account_number', 'hubtel_api_id', 'hubtel_api_key']);
        });
    }
};
