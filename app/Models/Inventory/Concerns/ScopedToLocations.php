<?php

namespace App\Models\Inventory\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Confines inventory reads to the locations a user is allowed to see.
 *
 * Implementers declare which columns tie the record to a location. A record is
 * visible when ANY of them falls inside the user's accessible set — a branch
 * manager must see stock arriving as well as stock leaving, so a transfer is
 * visible from either end.
 */
trait ScopedToLocations
{
    /**
     * Columns on this model holding an inventory location foreign key.
     *
     * @return array<int, string>
     */
    abstract protected function locationScopeColumns(): array;

    /**
     * Restrict a query to records the given user may read.
     *
     * A null user resolves to no rows rather than every row — these routes are
     * authenticated, but the scope must fail closed if that ever changes.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        $ids = $user->accessibleLocationIds();

        // null means unrestricted; an empty array means genuinely nothing.
        if ($ids === null) {
            return $query;
        }

        $columns = $this->locationScopeColumns();

        return $query->where(function (Builder $q) use ($columns, $ids) {
            foreach ($columns as $column) {
                $q->orWhereIn($column, $ids);
            }
        });
    }

    /**
     * Whether an already-loaded record is readable by the given user.
     *
     * Used by `show()` routes, where model binding has already resolved the
     * record and the list scope never ran.
     */
    public function isVisibleTo(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $ids = $user->accessibleLocationIds();

        if ($ids === null) {
            return true;
        }

        foreach ($this->locationScopeColumns() as $column) {
            if (in_array($this->{$column}, $ids, true)) {
                return true;
            }
        }

        return false;
    }
}
