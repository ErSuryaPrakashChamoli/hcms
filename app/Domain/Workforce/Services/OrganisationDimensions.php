<?php

namespace App\Domain\Workforce\Services;

use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Organisation\Services\OrganisationTree;
use Throwable;

/**
 * Phase 10: the organisation dimensions implied by an organisation node (nearest ancestor of each
 * type wins) and by a location (its establishment and legal entity). Nothing is stored twice: the
 * node, location and establishment stay the owners; positions, plans and budgets keep these ids for
 * organisation scoping.
 */
final class OrganisationDimensions
{
    public const UNIT_COLUMNS = ['business_unit_id', 'division_id', 'department_id', 'team_id'];

    public function __construct(private readonly OrganisationTree $tree) {}

    /** @return array{company_id: ?int, business_unit_id: ?int, division_id: ?int, department_id: ?int, team_id: ?int, location_id: ?int} */
    public function forNode(?int $nodeId): array
    {
        $dims = ['company_id' => null, 'business_unit_id' => null, 'division_id' => null, 'department_id' => null, 'team_id' => null, 'location_id' => null];
        if (! $nodeId) {
            return $dims;
        }
        $node = OrganisationNode::query()->findOrFail($nodeId);
        $ids = array_values(array_filter(explode('/', trim((string) $node->path, '/'))));
        $nodes = OrganisationNode::query()->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) { // root first, so the nearest ancestor of each type wins
            $current = $nodes->get((int) $id);
            if ($current === null) {
                continue;
            }
            try {
                $dims[$this->tree->typeKeyForClass($current->nodeable_type).'_id'] = (int) $current->nodeable_id;
            } catch (Throwable) {
                continue;
            }
        }
        $dims['company_id'] ??= $this->tree->nearestCompanyId($node);

        return $dims;
    }

    /** @return array{establishment_id: ?int, legal_entity_id: ?int} */
    public function forLocation(?int $locationId, ?int $establishmentId = null): array
    {
        $establishmentId ??= $locationId ? Location::query()->whereKey($locationId)->value('establishment_id') : null;

        return ['establishment_id' => $establishmentId, 'legal_entity_id' => $establishmentId ? Establishment::query()->whereKey($establishmentId)->value('legal_entity_id') : null];
    }
}
