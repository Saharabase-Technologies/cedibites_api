<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Fills in who took each past payment, and the shift they were on, once, on
 * every environment the code reaches. The user's word, 2026-10-15: "yes
 * backfill on prod with shift and make sure it applies when we push to main
 * prod". A migration runs once per database, on the deploy that brings it.
 *
 * The guesses are marked collector_inferred. See BackfillPaymentCollectors.
 * Prod held 7,342 payments and 1,836 shifts on 2026-10-15: seconds.
 *
 * Nothing to undo: the columns themselves come and go with
 * 2026_10_15_180000_add_collector_to_payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A fresh database built long after the command is gone has nothing
        // to backfill anyway.
        if (! array_key_exists('payments:backfill-collectors', Artisan::all())) {
            return;
        }

        Artisan::call('payments:backfill-collectors', ['--apply' => true, '--shifts' => true]);

        Log::info('Backfilled who took each past payment', ['output' => Artisan::output()]);
    }

    public function down(): void
    {
        //
    }
};
