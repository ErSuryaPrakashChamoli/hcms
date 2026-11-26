<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Enums\GeoPlaceKind;
use App\Domain\Organisation\Imports\Concerns\ReadsMasterRows;
use App\Domain\Organisation\Imports\Concerns\RequiresUpdatePermission;
use App\Domain\Organisation\Models\GeoPlace;
use App\Support\Imports\ClearValue;
use Illuminate\Database\Eloquent\Builder;

/**
 * Data import DI-1 (Core → States, Districts, Cities): the organisation's own places, one kind per upload, matched on
 * the code within that kind (a place's code is official, so it stays required). The state or district a row names may
 * be given by its code or its exact name (names first). The place rules (a district in a state, a city in a state and
 * one of its districts, a state's ISO region on the platform list) are the model's, checked again on save.
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

    /** A place's code is official: it stays required (never made from the name). */
    protected function nameField(): ?string
    {
        return null;
    }

    /** The id of a state on the list by its code or name, or an error. @param list<string> $errors */
    protected function stateId(?string $value, array $context, array &$errors): ?int
    {
        return $this->placeId(GeoPlaceKind::State, $value, $context, 'State', $errors);
    }

    /**
     * The id of a place of a kind by its code, else by its exact name (any case) among places of that kind; a name
     * several places of the kind share is refused (the code says which). Otherwise as parentId(): a place an earlier
     * worksheet adds, or "does not exist".
     *
     * @param  list<string>  $errors
     */
    protected function placeId(GeoPlaceKind $kind, ?string $value, array $context, string $what, array &$errors): ?int
    {
        $constrain = fn (Builder $q) => $q->where('kind', $kind->value);
        if ($value !== null && trim($value) !== '' && ! ClearValue::isClear($value)) {
            $places = GeoPlace::query()->withoutGlobalScope(AccessScope::class)->where('kind', $kind->value);
            if (! (clone $places)->whereRaw('lower(code) = ?', [mb_strtolower(trim($value))])->exists()) {
                $named = (clone $places)->whereRaw('lower(name) = ?', [mb_strtolower(trim($value))])->orderBy('id')->get(['id', 'code']);
                if ($named->count() === 1) {
                    return (int) $named->first()->id;
                }
                if ($named->count() > 1) {
                    $errors[] = "Several places are called '".trim($value)."' ({$named->pluck('code')->implode(', ')}): give the code of the one you mean in {$what}.";

                    return null;
                }
            }
        }

        return $this->parentId(GeoPlace::class, $value, $context, $what, $errors, $constrain, sameList: false);
    }

    /** The first name on file of a kind, for the sample file. */
    protected static function firstName(GeoPlaceKind $kind, string $fallback): string
    {
        return GeoPlace::query()->where('kind', $kind->value)->orderBy('id')->value('name') ?? $fallback;
    }

    protected function statusNote(): array
    {
        return ['Status', 'No', 'active or inactive. Blank for a new one is active.', 'active'];
    }
}
