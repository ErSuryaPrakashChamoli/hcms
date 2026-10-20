<?php

namespace App\Domain\Workforce\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Workforce\Models\Position;
use Illuminate\Support\Collection;

/**
 * Phase 10: who may see which positions. workforce.view / workforce.manage within organisation scope
 * (fail-closed on every scoped dimension). workforce.team (managers) sees only the positions under the
 * positions they occupy (position hierarchy) and the positions held by the employees they manage
 * through configured relationship types — mentors, buddies and project leads grant nothing. Costs need
 * workforce.costs. Employees see nothing about workforce planning by default.
 */
final class WorkforceAccess
{
    public function __construct(
        private readonly AccessScopes $scopes,
        private readonly PerformanceRelationships $relationships,
        private readonly PositionHierarchy $hierarchy,
        private readonly PositionOccupancy $occupancy,
    ) {}

    public function mayView(User $user, Position $position): bool
    {
        if (($user->hasPermission('workforce.view') || $user->hasPermission('workforce.manage')) && $this->scopes->allows($user, $position)) {
            return true;
        }

        return $user->hasPermission('workforce.team') && $this->teamPositionIds($user)->contains((int) $position->id);
    }

    public function mayManage(User $user, Position $position): bool
    {
        return $user->hasPermission('workforce.manage') && $this->scopes->allows($user, $position);
    }

    /** @return Collection<int, int> */
    public function teamPositionIds(User $user): Collection
    {
        if (! $user->hasPermission('workforce.team')) {
            return collect();
        }
        $me = $this->relationships->forUser($user);
        if ($me === null) {
            return collect();
        }
        $held = $this->occupancy->occupantRows(null, now())
            ->where('employee_positions.employee_id', $me->id)->pluck('employee_positions.position_id')->map(fn ($id) => (int) $id);
        $reports = $this->relationships->reportIds($me);
        $reportPositions = $reports->isEmpty() ? collect() : $this->occupancy->occupantRows(null, now())
            ->whereIn('employee_positions.employee_id', $reports)->pluck('employee_positions.position_id')->map(fn ($id) => (int) $id);

        return $this->hierarchy->descendants($held->all())->map(fn ($id) => (int) $id)->merge($reportPositions)->unique()->values();
    }
}
