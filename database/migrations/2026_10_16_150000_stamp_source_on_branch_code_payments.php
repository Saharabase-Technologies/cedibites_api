<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A branch code payment linked to an order is told apart from a MoMo prompt
 * by its source, `branch_code_link`. The few linked on beta before the source
 * column existed have none. Every one of them has a hubtel_incoming_payments
 * row pointing at it, so stamp those, and the source alone says USSD from here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('payments')
            ->whereNull('source')
            ->where('payment_method', 'mobile_money')
            ->whereIn('id', DB::table('hubtel_incoming_payments')->whereNotNull('payment_id')->select('payment_id'))
            ->update(['source' => 'branch_code_link']);
    }

    public function down(): void
    {
        // Nothing to undo: which rows had no source before is not kept.
    }
};
