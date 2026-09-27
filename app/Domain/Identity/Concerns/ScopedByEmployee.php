<?php

namespace App\Domain\Identity\Concerns;

use App\Domain\Identity\Scopes\AccessScope;

/**
 * Employee-linked records (employee_id) restricted to the authenticated user's access scope.
 */
trait ScopedByEmployee
{
    public static function bootScopedByEmployee(): void
    {
        static::addGlobalScope(new AccessScope);
    }
}
