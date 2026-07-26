<?php

namespace App\Http\Controllers\Api\Inventory\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Case-insensitive text search across database drivers.
 *
 * Production runs Postgres, where `LIKE` is case-SENSITIVE — so searching
 * "basma" found nothing while "Basma" found Basmati Rice, and typing a
 * reference as "req-260726" matched none of the uppercase REQ- rows. Every
 * search box in the portal had this.
 *
 * SQLite (the test driver) already folds case for ASCII `LIKE`, so picking the
 * operator per driver gives the same behaviour in tests as in production
 * without resorting to `LOWER(col)`, which would throw away the index.
 */
trait SearchesText
{
    protected function likeOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    /** Wrap a raw search term for a contains-match. */
    protected function likeTerm(string $term): string
    {
        return '%'.trim($term).'%';
    }
}
