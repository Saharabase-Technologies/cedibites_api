<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of a claimed loss the approver actually allowed.
 *
 * The whole point of sending goods back to the warehouse is that somebody looks
 * at them: *"So show me the food that has gone bad."* Looking has three possible
 * answers, and the system only had two. A branch returns 20 kg saying it is
 * spoiled; the warehouse manager opens the crate and finds 10 kg perfectly
 * usable. Until now he had to write off all 20 or refuse all 20 - so he either
 * destroyed good food on paper, or called an honest claim a lie.
 *
 * `quantity` stays exactly what the branch DECLARED. `approved_qty` is what was
 * allowed. Keeping both is the point: the gap between them is the record of how
 * accurately a branch judges its own stock, which is precisely the data the
 * founder asked for - "so that we know when they are making too many mistakes".
 * Collapsing them into one column would erase that.
 *
 * Nullable, and read as "all of it" when null: every claim that settled before
 * this existed was approved in full, and that is what it should keep meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_wastage_lines', function (Blueprint $table) {
            $table->decimal('approved_qty', 14, 4)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_wastage_lines', function (Blueprint $table) {
            $table->dropColumn('approved_qty');
        });
    }
};
