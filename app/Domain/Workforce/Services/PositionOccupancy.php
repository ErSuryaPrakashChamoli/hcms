<?php

namespace App\Domain\Workforce\Services;

use App\Domain\Employment\Exceptions\PositionUnavailableException;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Models\PositionVersion;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Phase 10 occupancy: who occupies a position on a date, derived from the authoritative
 * employee_positions rows that carry the position — never from a typed status. An employee stops
 * occupying the day after they exit: the exit date and the lifecycle history are both honoured, so a
 * rehired employee's gap is not counted (exits do not close employee_positions rows).
 */
final class PositionOccupancy
{
    private const EPSILON = 0.0001;

    /** Occupant rows effective on a day for the given positions, or any position when null (no access scope: callers decide visibility). */
    public function occupantRows(?array $positionIds, CarbonInterface|string|null $on = null): Builder
    {
        $day = Carbon::parse($on ?? now())->toDateString();

        return EmployeePosition::query()->withoutGlobalScope(AccessScope::class)
            ->when($positionIds === null, fn ($q) => $q->whereNotNull('employee_positions.position_id'), fn ($q) => $q->whereIn('employee_positions.position_id', $positionIds))
            ->effectiveOn($day)
            ->whereIn('employee_positions.employee_id', Employee::query()->withoutGlobalScope(AccessScope::class)->select('employees.id')
                ->where(fn ($q) => $q->whereNull('employees.exit_date')->orWhere('employees.exit_date', '>=', $day)))
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('employee_lifecycle_transitions as left_transition')
                ->whereColumn('left_transition.employee_id', 'employee_positions.employee_id')
                ->whereIn('left_transition.to_state', ['exited', 'alumni'])
                ->whereColumn('left_transition.effective_date', '>=', 'employee_positions.effective_from')
                ->where('left_transition.effective_date', '<', $day));
    }

    /**
     * Occupancy of one position on a day: capacity, occupants and what remains.
     *
     * @return array{in_force: bool, status: ?string, seats: int, fte_capacity: float, fte_per_seat: float, occupied_seats: int, occupied_fte: float, remaining_seats: int, remaining_fte: float, state: string, occupants: list<array{employee_id: int, employee_code: ?string, name: ?string, fte: float, since: ?string}>}
     */
    public function occupancy(Position $position, CarbonInterface|string|null $on = null): array
    {
        $day = Carbon::parse($on ?? now())->toDateString();
        $version = PositionVersion::query()->withoutGlobalScope(AccessScope::class)->where('position_id', $position->id)->effectiveOn($day)->orderByDesc('version')->first();
        $perSeat = (float) ($version?->fte ?? 1);
        $occupants = $this->occupantRows([$position->id], $day)->with('employee.person')->orderBy('employee_positions.effective_from')->get()
            ->unique('employee_id')->map(fn (EmployeePosition $row) => [
                'employee_id' => (int) $row->employee_id, 'employee_code' => $row->employee?->employee_code, 'name' => $row->employee?->person?->full_name,
                'fte' => (float) ($row->fte ?? $perSeat), 'since' => $row->effective_from?->toDateString(),
            ])->values()->all();
        $seats = (int) ($version?->headcount ?? 0);
        $capacity = (float) ($version?->fte_capacity ?? 0);
        $occupiedFte = round(array_sum(array_column($occupants, 'fte')), 2);

        return [
            'in_force' => $version !== null, 'status' => $version?->status, 'seats' => $seats, 'fte_capacity' => $capacity, 'fte_per_seat' => $perSeat,
            'occupied_seats' => count($occupants), 'occupied_fte' => $occupiedFte,
            'remaining_seats' => max(0, $seats - count($occupants)), 'remaining_fte' => max(0.0, round($capacity - $occupiedFte, 2)),
            'state' => $version === null ? 'not_in_force' : (count($occupants) === 0 ? 'vacant' : ($occupiedFte + self::EPSILON >= $capacity || count($occupants) >= $seats ? 'filled' : 'partially_filled')),
            'occupants' => $occupants,
        ];
    }

    /**
     * Occupied seats and FTE per position on a day, in one grouped query.
     *
     * @param  list<int>  $positionIds
     * @return Collection<int, object{seats: int, fte: float}>
     */
    public function occupancyFor(array $positionIds, CarbonInterface|string|null $on = null): Collection
    {
        if ($positionIds === []) {
            return collect();
        }
        $day = Carbon::parse($on ?? now())->toDateString();

        return $this->occupantRows($positionIds, $day)
            ->join('position_versions as occupancy_version', fn ($j) => $j->on('occupancy_version.position_id', '=', 'employee_positions.position_id')
                ->where('occupancy_version.effective_from', '<=', $day.' 23:59:59')
                ->where(fn ($w) => $w->whereNull('occupancy_version.effective_to')->orWhere('occupancy_version.effective_to', '>=', $day)))
            ->groupBy('employee_positions.position_id')
            ->selectRaw('employee_positions.position_id as position_id, count(distinct employee_positions.employee_id) as seats, sum(coalesce(employee_positions.fte, occupancy_version.fte)) as fte')
            ->get()->keyBy('position_id')->map(fn ($r) => (object) ['seats' => (int) $r->seats, 'fte' => round((float) $r->fte, 2)]);
    }

    /**
     * Refuse an assignment the position cannot take from $from onwards. The caller holds the position
     * row lock; assignment rows are read with a locking read so a concurrent assignment that has just
     * committed is seen (REPEATABLE READ would otherwise answer from an older snapshot).
     *
     * @return PositionVersion the version in force on $from
     */
    public function assertCanAssign(Position $position, Employee $employee, Carbon $from, ?float $fte): PositionVersion
    {
        $day = $from->toDateString();
        $versions = PositionVersion::query()->withoutGlobalScope(AccessScope::class)->where('position_id', $position->id)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereColumn('effective_to', '>=', 'effective_from'))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $day))
            ->lockForUpdate()->orderBy('version')->get();
        $current = $versions->first(fn (PositionVersion $v) => $v->effective_from->toDateString() <= $day);
        if ($current === null) {
            throw new PositionUnavailableException("Position {$position->code} is not in force on {$day}.");
        }
        if (! in_array($current->status, config('peopleos.workforce.assignable_statuses', ['open']), true)) {
            throw new PositionUnavailableException("Position {$position->code} is {$current->status} on {$day} and cannot take a new assignment.");
        }
        if ($versions->contains(fn (PositionVersion $v) => in_array($v->status, ['abolished', 'closed'], true))) {
            throw new PositionUnavailableException("Position {$position->code} is scheduled to be abolished or closed.");
        }
        if ($employee->lifecycle_state !== null && in_array($employee->lifecycle_state->value, ['exited', 'alumni'], true)) {
            throw new PositionUnavailableException('An employee who has left cannot be assigned to a position.');
        }
        $seatFte = $fte ?? (float) $current->fte;
        if ($seatFte <= 0 || $seatFte > (float) $current->fte + self::EPSILON) {
            throw new PositionUnavailableException("The assignment FTE must be above zero and at most the position's {$current->fte} FTE per seat.");
        }

        [$seats, $usedFte] = $this->usedFrom($position, $from, $employee->id, (float) $current->fte);
        $minSeats = (int) $versions->min('headcount');
        $minFte = (float) $versions->min('fte_capacity');
        if ($seats + 1 > $minSeats) {
            throw new PositionUnavailableException("Position {$position->code} has no free seat from {$day} ({$seats} of {$minSeats} taken).");
        }
        if ($usedFte + $seatFte > $minFte + self::EPSILON) {
            throw new PositionUnavailableException("Position {$position->code} has no FTE capacity left from {$day} (".round($usedFte, 2)." of {$minFte} FTE used).");
        }

        return $current;
    }

    /**
     * Seats and FTE taken by other employees on any day from $from onwards (conservative: every
     * assignment row that overlaps the open-ended period counts), read with a locking read.
     *
     * @return array{0: int, 1: float}
     */
    public function usedFrom(Position $position, Carbon $from, ?int $exceptEmployeeId, float $defaultFte): array
    {
        $day = $from->toDateString();
        $rows = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('position_id', $position->id)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $day))
            ->when($exceptEmployeeId, fn ($q, $id) => $q->where('employee_id', '!=', $id))
            ->lockForUpdate()->get(['employee_id', 'fte']);
        if ($rows->isEmpty()) {
            return [0, 0.0];
        }
        $leavers = Employee::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $rows->pluck('employee_id')->unique())
            ->whereNotNull('exit_date')->where('exit_date', '<', $day)->pluck('id')->all();
        $perEmployee = $rows->reject(fn ($r) => in_array($r->employee_id, $leavers, true))
            ->groupBy('employee_id')->map(fn ($group) => (float) $group->max(fn ($r) => (float) ($r->fte ?? $defaultFte)));

        return [$perEmployee->count(), (float) $perEmployee->sum()];
    }
}
