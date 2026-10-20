<?php

namespace App\Domain\Workforce\Services;

use App\Domain\Employment\Contracts\PositionAssignmentGuard;
use App\Domain\Employment\Exceptions\PositionUnavailableException;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Workforce\Events\WorkforceEvent;
use App\Domain\Workforce\Models\Position;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Phase 10 implementation of the employment boundary: assigning an employee to a position locks the
 * position row, re-validates it (in force, open, not frozen / abolished, capacity and FTE left from
 * the effective date) and returns its dimensions; vacating is explicit; any other employment change
 * carries the seat forward unchanged. Nobody is moved, promoted or terminated here.
 */
final class PositionSeats implements PositionAssignmentGuard
{
    public function __construct(private readonly PositionOccupancy $occupancy) {}

    public function resolve(Employee $employee, ?EmployeePosition $current, array $attributes, CarbonInterface $from): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $previousPositionId = $current?->position_id ? (int) $current->position_id : null;

        if (filled($attributes['position_id'] ?? null)) {
            $positionId = (int) $attributes['position_id'];
            $fte = filled($attributes['fte'] ?? null) ? (float) $attributes['fte'] : null;
            $position = Position::query()->withoutGlobalScope(AccessScope::class)->whereKey($positionId)->lockForUpdate()->first();
            if ($position === null) {
                throw new PositionUnavailableException('That position does not exist in this organisation.');
            }
            // Defence in depth: a scoped user assigns only to positions inside their organisation scope.
            $actor = auth()->user();
            if ($actor instanceof User && ! app(AccessScopes::class)->allows($actor, $position)) {
                throw new PositionUnavailableException('That position is outside your organisation scope.');
            }
            $version = $this->occupancy->assertCanAssign($position, $employee, $from, $fte);
            $dimensions = collect(['company_id', 'location_id', 'business_unit_id', 'division_id', 'department_id', 'team_id', 'designation_id', 'grade_id', 'employment_type_id', 'cost_centre_id'])
                ->mapWithKeys(fn ($column) => [$column => $version->getAttribute($column) !== null ? (int) $version->getAttribute($column) : null])->filter()->all();
            if ($previousPositionId !== $positionId) {
                WorkforceEvent::dispatch('workforce.position.occupied', $employee, $position, ['code' => $position->code, 'effective_date' => $from->toDateString()], array_filter([(int) $position->created_by]));
                $this->announceVacated($previousPositionId, $employee, $from);
            }

            return ['position_id' => $positionId, 'fte' => $fte, 'dimensions' => $dimensions];
        }

        if (! empty($attributes['vacate_position'])) {
            $this->announceVacated($previousPositionId, $employee, $from);

            return ['position_id' => null, 'fte' => null, 'dimensions' => []];
        }

        return ['position_id' => $previousPositionId, 'fte' => $current?->fte !== null ? (float) $current->fte : null, 'dimensions' => []];
    }

    private function announceVacated(?int $positionId, Employee $employee, Carbon $from): void
    {
        if ($positionId === null) {
            return;
        }
        $position = Position::query()->withoutGlobalScope(AccessScope::class)->find($positionId);
        if ($position !== null) {
            WorkforceEvent::dispatch('workforce.position.vacated', null, $position, ['code' => $position->code, 'effective_date' => $from->toDateString()], array_filter([(int) $position->created_by]));
        }
    }
}
