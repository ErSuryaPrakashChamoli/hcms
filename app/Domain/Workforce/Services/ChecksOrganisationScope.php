<?php

namespace App\Domain\Workforce\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Phase 10 defence in depth: workforce services re-check the actor's organisation scope (not only the
 * screens' policies). An existing record must be visible to the actor; a new record's dimensions must
 * all fall inside every dimension the actor is scoped on (fail-closed when a dimension is missing).
 */
trait ChecksOrganisationScope
{
    protected function assertRecordInScope(User $actor, Model $record): void
    {
        if (! app(AccessScopes::class)->allows($actor, $record)) {
            throw new RuntimeException('That record is outside your organisation scope.');
        }
    }

    /** @param  array<string, mixed>  $dimensions  keyed by column (company_id, location_id, department_id …) */
    protected function assertDimensionsInScope(User $actor, array $dimensions): void
    {
        foreach (app(AccessScopes::class)->for($actor) ?? [] as $dimension => $ids) {
            $value = $dimensions["{$dimension}_id"] ?? null;
            if ($value === null || ! in_array((int) $value, array_map('intval', $ids), true)) {
                throw new RuntimeException("That {$dimension} is outside your organisation scope.");
            }
        }
    }
}
