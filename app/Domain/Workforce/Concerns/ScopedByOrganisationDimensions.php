<?php

namespace App\Domain\Workforce\Concerns;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 10: organisation scoping for records that carry several organisation dimensions (positions,
 * position versions, workforce plans, budgets). For every dimension in the user's scope the record's
 * own column must be one of the scoped ids — a record without a value for a scoped dimension is not
 * visible (fail-closed). Unscoped users and system contexts are not restricted (AccessScope).
 */
trait ScopedByOrganisationDimensions
{
    public static function bootScopedByOrganisationDimensions(): void
    {
        static::addGlobalScope(new AccessScope);
    }

    public function applyAccessScope(Builder $query, User $user, AccessScopes $scopes): void
    {
        foreach ($scopes->for($user) ?? [] as $dimension => $ids) {
            $column = "{$dimension}_id";
            if (in_array($column, $this->getFillable(), true)) {
                $query->whereIn($this->qualifyColumn($column), $ids);
            } else {
                // A dimension the record cannot express keeps it hidden from users scoped on it.
                $query->whereRaw('1 = 0');
            }
        }
    }
}
