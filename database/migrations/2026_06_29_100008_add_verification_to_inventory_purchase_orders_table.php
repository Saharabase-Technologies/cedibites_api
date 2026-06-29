<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anti-fraud signature: every PO gets an unguessable verification code (rendered
 * as a QR on the PO document) that a super-admin can look up to confirm
 * authenticity. POs are soft-deleted so historical records are never lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_purchase_orders', function (Blueprint $table) {
            $table->string('verification_code', 32)->nullable()->unique()->after('reference');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['verification_code', 'deleted_at']);
        });
    }
};
