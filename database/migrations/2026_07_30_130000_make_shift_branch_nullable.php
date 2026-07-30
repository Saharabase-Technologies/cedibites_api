<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shift does not always belong to a branch.
 *
 * The call centre works a shift like everyone else — they sit down, take calls,
 * and go home — but they belong to no branch, because the branch is a property
 * of each order they place rather than of where they are sitting. `branch_id`
 * being NOT NULL meant they could not start one at all: the client sent an empty
 * string, the API rejected it, and the failure was swallowed on every login. So
 * they had no shifts, and therefore no My Shifts and no My Sales.
 *
 * Nothing is lost by allowing null. A shift's takings are read through
 * `shift_orders` → `orders.branch_id`, so a call-centre shift can still be
 * broken down by branch — by the branches its orders went to, which is the
 * honest answer, rather than by one branch the agent never sat in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Any call-centre shift written while this was up has no branch to put
        // back, so tightening the column would fail on real data. Left nullable
        // deliberately: a rollback that cannot complete is worse than a column
        // that permits a value nothing writes any more.
    }
};
