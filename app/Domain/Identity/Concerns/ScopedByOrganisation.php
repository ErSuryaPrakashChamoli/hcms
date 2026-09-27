<?php

namespace App\Domain\Identity\Concerns;

use App\Domain\Identity\Scopes\AccessScope;

/**
 * Organisation units restricted to the authenticated user's access scope. The model declares
 * `public string $accessScopeDimension` (company, location, business_unit, division, department,
 * team; units without their own dimension use company).
 */
trait ScopedByOrganisation
{
    public static function bootScopedByOrganisation(): void
    {
        static::addGlobalScope(new AccessScope);
    }
}
