<?php

namespace App\Domain\Organisation\Services;

use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Exceptions\InvalidHierarchyException;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Location;

/**
 * Data import DI-1 (Core → Sub branches): a location may sit inside another location of the same company (a sub
 * branch inside its branch). Checked on every save of a location, from the form or an upload: the parent is in the
 * same company and is neither the location itself nor one of its own sub branches; a location with sub branches keeps
 * its company (move the sub branches first).
 */
final class LocationHierarchy
{
    /**
     * Master relationships R4: a location's establishment is one of the location's own company, and a location shared
     * with other companies takes none (their people would use that company's registrations for PF, ESI, PT and LWF).
     * Checked on every save that sets or moves it, from the form or an upload.
     */
    public function assertEstablishment(Location $location): void
    {
        $id = $location->getAttribute('establishment_id');
        if (blank($id) || ($location->exists && ! $location->isDirty(['establishment_id', 'company_id']))) {
            return;
        }
        $owner = Establishment::query()->withoutGlobalScope(AccessScope::class)->whereKey($id)->value('company_id');
        if ($owner === null) {
            throw new InvalidHierarchyException('The establishment does not exist.');
        }
        if ((int) $owner !== (int) $location->getAttribute('company_id')) {
            throw new InvalidHierarchyException("The establishment belongs to another company: choose one of the location's own company.");
        }
        if ($location->exists) {
            $reach = app(EntityApplicability::class)->reach($location);
            if ($reach === null || array_diff($reach, [(int) $owner]) !== []) {
                throw new InvalidHierarchyException('This location is shared with other companies, so it cannot take one company\'s establishment: their people would use its registrations (PF, ESI, PT, LWF). Keep the location to its company first, or leave the establishment blank.');
            }
        }
    }

    public function assertValid(Location $location): void
    {
        $attributes = $location->getAttributes();
        $parentId = $attributes['parent_location_id'] ?? null;
        $companyId = $attributes['company_id'] ?? null;

        if ($location->exists && $location->isDirty('company_id')
            && Location::query()->withoutGlobalScope(AccessScope::class)->where('parent_location_id', $location->getKey())->where('company_id', '!=', $companyId)->exists()) {
            throw new InvalidHierarchyException('This branch has sub branches in its company: move them to the other company first.');
        }
        if ($parentId === null || ! ($location->isDirty('parent_location_id') || $location->isDirty('company_id'))) {
            return;
        }

        $parent = Location::query()->withoutGlobalScope(AccessScope::class)->find((int) $parentId);
        if ($parent === null) {
            throw new InvalidHierarchyException('The parent branch does not exist.');
        }
        if ((int) $parent->company_id !== (int) $companyId) {
            throw new InvalidHierarchyException('A sub branch belongs to a branch of the same company.');
        }
        $seen = [];
        $current = (int) $parentId;
        while ($current !== 0 && ! isset($seen[$current])) {
            if ($location->exists && $current === (int) $location->getKey()) {
                throw new InvalidHierarchyException('A location cannot sit inside itself or one of its own sub branches.');
            }
            $seen[$current] = true;
            $current = (int) Location::query()->withoutGlobalScope(AccessScope::class)->whereKey($current)->value('parent_location_id');
        }
    }
}
