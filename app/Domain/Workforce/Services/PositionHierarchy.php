<?php

namespace App\Domain\Workforce\Services;

use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Models\PositionVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Phase 10 position hierarchy (parent positions, effective-dated on the version). It is not the
 * employee reporting hierarchy, which stays explicit in reporting_relationships and is never derived
 * from it. Guards: no self-parent, no cycle (on the date and in the latest definitions), same company,
 * same tenant (the tenant scope makes a foreign position unreachable), no abolished / closed parent.
 */
final class PositionHierarchy
{
    public function assertParent(?Position $position, ?int $parentId, int $companyId, Carbon $on): void
    {
        if ($parentId === null) {
            return;
        }
        if ($position !== null && (int) $position->id === $parentId) {
            throw new RuntimeException('A position cannot be its own parent.');
        }
        $parent = Position::query()->withoutGlobalScope(AccessScope::class)->find($parentId);
        if ($parent === null) {
            throw new RuntimeException('The parent position does not exist in this organisation.');
        }
        if ((int) $parent->company_id !== $companyId) {
            throw new RuntimeException('A parent position must belong to the same company.');
        }
        if (in_array($parent->status, ['abolished', 'closed'], true)) {
            throw new RuntimeException('An abolished or closed position cannot be a parent.');
        }
        if ($position === null) {
            return; // a new position cannot close a loop
        }
        foreach ([$on->toDateString(), null] as $day) {
            $seen = [];
            for ($current = $parentId; $current !== null; $current = $this->parentOf($current, $day)) {
                if ($current === (int) $position->id) {
                    throw new RuntimeException('That parent would make the position hierarchy circular.');
                }
                if (isset($seen[$current]) || count($seen) > 500) {
                    break;
                }
                $seen[$current] = true;
            }
        }
    }

    /** Parent of a position on a date (null = in its latest definition). */
    public function parentOf(int $positionId, ?string $day): ?int
    {
        $query = PositionVersion::query()->withoutGlobalScope(AccessScope::class)->where('position_id', $positionId);
        $version = $day === null ? $query->orderByDesc('version')->first() : $query->effectiveOn($day)->orderByDesc('version')->first();

        return $version?->parent_position_id ? (int) $version->parent_position_id : null;
    }

    /**
     * Positions under the given ones on a date (descendants through parent links), one query per
     * level, never a query per position.
     *
     * @param  list<int>  $rootIds
     * @return Collection<int, int>
     */
    public function descendants(array $rootIds, ?string $day = null): Collection
    {
        $day ??= now()->toDateString();
        $found = collect();
        $frontier = collect($rootIds);
        for ($depth = 0; $frontier->isNotEmpty() && $depth < 50; $depth++) {
            $children = PositionVersion::query()->withoutGlobalScope(AccessScope::class)->effectiveOn($day)
                ->whereIn('parent_position_id', $frontier->all())->pluck('position_id')->unique()->diff($found)->diff($rootIds);
            $found = $found->merge($children);
            $frontier = $children;
        }

        return $found->values();
    }
}
