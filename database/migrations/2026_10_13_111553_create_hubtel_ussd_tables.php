<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The branch code becomes our own USSD service.
     *
     * Until now a customer dialling *713*1552# met Hubtel's own menu, and the
     * payment reached us with nothing to say which branch it was for: Hubtel
     * keeps one notification URL for the whole business. With Hubtel's
     * Programmable Services every dial comes to our server instead. We know
     * the code that was dialled, so we know the branch, and a dial that
     * carries the amount (*713*1552*50#) is one confirm away from the PIN.
     *
     * hubtel_ussd_sessions holds one row per dial: who dialled, which branch,
     * how much, and every post and answer exchanged with Hubtel. Hubtel's
     * real posts are not fully documented, so the trail is how we read them.
     *
     * hubtel_ussd_fulfilments holds what Hubtel sends when a dial is paid,
     * whole, with what became of it. Like the payment notifications, a post
     * is never trusted: the status check decides.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            // The branch's part of the code: 1552 in *713*1552#.
            $table->string('hubtel_ussd_code', 12)->nullable()->unique();
        });

        Schema::create('hubtel_ussd_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 64)->unique();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mobile', 20)->nullable();
            $table->string('operator', 20)->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('service_code', 40)->nullable();
            // The first Message, as Hubtel sent it.
            $table->string('dialled', 120)->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            // dial, typed
            $table->string('amount_from', 8)->nullable();
            // asked_branch, asked_amount, asked_confirm, sent_to_pay, paid, cancelled, refused
            $table->string('state', 20);
            $table->unsignedTinyInteger('bad_tries')->default(0);
            $table->string('hubtel_order_id', 64)->nullable();
            // When Hubtel took our fulfilment callback, and what it last said.
            $table->timestamp('acked_at')->nullable();
            $table->string('ack_note', 255)->nullable();
            $table->json('trail')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'created_at']);
        });

        Schema::create('hubtel_ussd_fulfilments', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 64)->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->json('payload')->nullable();
            $table->text('raw_body')->nullable();
            $table->json('headers')->nullable();
            // paid, duplicate, failed, not_paid, not_found, mismatch, unknown_session, unreadable
            $table->string('outcome', 20)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        Schema::table('hubtel_incoming_payments', function (Blueprint $table) {
            // The name MoMo holds for the payer, from Hubtel's fulfilment.
            $table->string('payer_name', 120)->nullable();
            $table->foreignId('ussd_session_id')->nullable()->constrained('hubtel_ussd_sessions')->nullOnDelete();
        });

        Schema::table('hubtel_incoming_payments', function (Blueprint $table) {
            // A payment through our service comes with no account number, and
            // a guessed one is worse than none.
            $table->string('account_number', 32)->nullable()->change();
        });
    }

    /**
     * account_number stays nullable: rows written since may hold none.
     */
    public function down(): void
    {
        Schema::table('hubtel_incoming_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ussd_session_id');
            $table->dropColumn('payer_name');
        });

        Schema::dropIfExists('hubtel_ussd_fulfilments');
        Schema::dropIfExists('hubtel_ussd_sessions');

        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique(['hubtel_ussd_code']);
            $table->dropColumn('hubtel_ussd_code');
        });
    }
};
