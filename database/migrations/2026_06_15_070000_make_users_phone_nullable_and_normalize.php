<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phone becomes optional (executives/partners may sign in with email only),
     * and existing phone numbers are canonicalised to "+233XXXXXXXXX" so that
     * login matches regardless of the format originally entered.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->change();
        });

        User::query()->whereNotNull('phone')->get(['id', 'phone'])->each(function (User $u) {
            $normalized = User::normalizePhone($u->phone);
            if (! $normalized || $normalized === $u->phone) {
                return;
            }
            // Don't create a duplicate against the unique index.
            $clash = User::where('phone', $normalized)->where('id', '!=', $u->id)->exists();
            if (! $clash) {
                DB::table('users')->where('id', $u->id)->update(['phone' => $normalized]);
            }
        });
    }

    public function down(): void
    {
        // Reverting to NOT NULL would fail if any null phones exist; intentionally
        // left as a no-op for the nullability change. Data normalisation is not reversed.
    }
};
