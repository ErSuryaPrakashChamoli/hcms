<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Organisation\Enums\GeoPlaceKind;
use App\Domain\Organisation\Imports\Concerns\ReadsMasterRows;
use App\Domain\Organisation\Imports\Concerns\RequiresUpdatePermission;
use App\Domain\Organisation\Models\GeoPlace;
use Illuminate\Database\Eloquent\Builder;

/**
 * Data import DI-1 (Core → States, Districts, Cities): the organisation's own places, one kind per upload, matched on
 * the code within that kind. The place rules (a district in a state, a city in a state and one of its districts, a
 * state's ISO region on the platform list) are the model's, checked again on save.
 */
abstract class GeoPlaceImports extends MasterImports
{
    use ReadsMasterRows, RequiresUpdatePermission;

    abstract protected function kind(): GeoPlaceKind;

    protected function updatePermission(): string
    {
        return 'organisation.update';
    }

    protected function module(): string
    {
        return 'organisation';
    }

    protected function model(): string
    {
        return GeoPlace::class;
    }

    protected function query(): Builder
    {
        return parent::query()->where('kind', $this->kind()->value);
    }

    /** The id of a state on the list by its code, or an error. @param list<string> $errors */
    protected function stateId(?string $code, array $context, array &$errors): ?int
    {
        return $this->parentId(GeoPlace::class, $code, $context, 'State code', $errors, fn (Builder $q) => $q->where('kind', GeoPlaceKind::State->value), sameList: false);
    }

    /** First codes on file of a kind, for the sample file. */
    protected static function firstCode(GeoPlaceKind $kind, string $fallback): string
    {
        return GeoPlace::query()->where('kind', $kind->value)->orderBy('id')->value('code') ?? $fallback;
    }

    protected function statusNote(): array
    {
        return ['Status', 'No', 'active or inactive. Blank for a new one is active.', 'active'];
    }
}
