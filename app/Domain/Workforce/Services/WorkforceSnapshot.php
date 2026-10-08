<?php

namespace App\Domain\Workforce\Services;

use App\Domain\Analytics\Services\WorkforceMetrics;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Models\PositionVersion;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10 workforce on any date, reconstructed from effective-dated records (position versions,
 * employee_positions and the lifecycle history) — no snapshot rows. Headcount is reported three ways
 * that are never interchanged: positions, seats / FTE (capacity) and employees (people). Database
 * aggregates under the caller's organisation scope; a constant number of queries whatever the size.
 */
final class WorkforceSnapshot
{
    public function __construct(private readonly WorkforceMetrics $metrics) {}

    /** Position versions in force on the day (one per position), under the caller's access scope. */
    public function versionsOn(CarbonInterface|string|null $on = null): Builder
    {
        return PositionVersion::query()->effectiveOn(Carbon::parse($on ?? now())->toDateString());
    }

    /**
     * Occupied seats and FTE per position on the day, as a grouped subquery (for joins).
     */
    public function occupancySubquery(CarbonInterface|string|null $on = null): QueryBuilder
    {
        $day = Carbon::parse($on ?? now())->toDateString();

        return EmployeePosition::query()->withoutGlobalScope(AccessScope::class)
            ->whereNotNull('employee_positions.position_id')
            ->effectiveOn($day)
            ->whereIn('employee_positions.employee_id', Employee::query()->withoutGlobalScope(AccessScope::class)->select('employees.id')
                ->where(fn ($q) => $q->whereNull('employees.exit_date')->orWhere('employees.exit_date', '>=', $day)))
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('employee_lifecycle_transitions as left_transition')
                ->whereColumn('left_transition.employee_id', 'employee_positions.employee_id')
                ->whereIn('left_transition.to_state', ['exited', 'alumni'])
                ->whereColumn('left_transition.effective_date', '>=', 'employee_positions.effective_from')
                ->where('left_transition.effective_date', '<', $day))
            ->join('position_versions as occupied_version', fn ($j) => $j->on('occupied_version.position_id', '=', 'employee_positions.position_id')
                ->where('occupied_version.effective_from', '<=', $day.' 23:59:59')
                ->where(fn ($w) => $w->whereNull('occupied_version.effective_to')->orWhere('occupied_version.effective_to', '>=', $day)))
            ->groupBy('employee_positions.position_id')
            ->selectRaw('employee_positions.position_id as position_id, count(distinct employee_positions.employee_id) as occupied_seats, sum(coalesce(employee_positions.fte, occupied_version.fte)) as occupied_fte')
            ->toBase();
    }

    /**
     * Headcount on a day: positions, seats and FTE by status, occupancy, vacancy and the employee count.
     *
     * @return array<string, mixed>
     */
    public function headcount(CarbonInterface|string|null $on = null, array $scope = []): array
    {
        $day = Carbon::parse($on ?? now())->toDateString();
        $rows = $this->withinScope($this->aggregate($day, null), $scope)->toBase()->get()->keyBy('status');
        $effective = config('peopleos.workforce.effective_statuses');
        $sum = fn (array $statuses, string $field) => round((float) $rows->only($statuses)->sum($field), 2);

        return [
            'date' => $day,
            'positions' => (int) $rows->only($effective)->sum('positions'),
            'approved_seats' => (int) $sum($effective, 'seats'), 'approved_fte' => $sum($effective, 'fte_capacity'),
            'planned_seats' => (int) $sum(['approved', 'planned'], 'seats'), 'planned_fte' => $sum(['approved', 'planned'], 'fte_capacity'),
            'open_seats' => (int) $sum(['open'], 'seats'),
            'occupied_seats' => (int) $rows->sum('occupied_seats'), 'occupied_fte' => round((float) $rows->sum('occupied_fte'), 2),
            'vacant_seats' => (int) $sum(['open'], 'unfilled_seats'), 'vacant_fte' => $sum(['open'], 'unfilled_fte'),
            'frozen_seats' => (int) $sum(['frozen'], 'seats'), 'on_hold_seats' => (int) $sum(['on_hold'], 'seats'),
            'future_positions' => Position::query()->whereIn('status', ['approved', 'planned'])->whereDate('first_effective_from', '>', $day)->count(),
            'employees' => $scope === [] ? $this->metrics->headcount($day) : null,
        ];
    }

    /**
     * Restrict a position-version query to an organisation scope (company, units, location,
     * establishment — only the keys given and not null).
     *
     * @param  array<string, int|null>  $scope
     */
    public function withinScope(Builder $query, array $scope): Builder
    {
        foreach (['company_id', 'business_unit_id', 'division_id', 'department_id', 'team_id', 'location_id', 'establishment_id'] as $column) {
            if (! empty($scope[$column])) {
                $query->where('position_versions.'.$column, $scope[$column]);
            }
        }

        return $query;
    }

    /**
     * Seats, FTE, occupancy and vacancy on a day grouped by one dimension of the position.
     *
     * @param  'department_id'|'location_id'|'job_family_id'|'employment_type_id'|'business_unit_id'|'grade_id'|'designation_id'|'company_id'  $dimension
     * @return list<array<string, mixed>>
     */
    public function by(string $dimension, CarbonInterface|string|null $on = null): array
    {
        $day = Carbon::parse($on ?? now())->toDateString();

        return $this->aggregate($day, $dimension)->whereIn('position_versions.status', config('peopleos.workforce.effective_statuses'))->toBase()->get()
            ->groupBy('dimension_id')->map(fn ($group, $id) => [
                'id' => $id === '' ? null : (int) $id,
                'positions' => (int) $group->sum('positions'), 'seats' => (int) $group->sum('seats'), 'fte' => round((float) $group->sum('fte_capacity'), 2),
                'occupied_seats' => (int) $group->sum('occupied_seats'), 'occupied_fte' => round((float) $group->sum('occupied_fte'), 2),
                'vacant_seats' => (int) $group->where('status', 'open')->sum('unfilled_seats'),
            ])->values()->all();
    }

    /**
     * The positions in force on a day with their occupancy (for the snapshot screen and API), paged by the caller.
     */
    public function positionsOn(CarbonInterface|string|null $on = null): Builder
    {
        $day = Carbon::parse($on ?? now())->toDateString();

        return $this->versionsOn($day)
            ->leftJoinSub($this->occupancySubquery($day), 'occupancy', 'occupancy.position_id', '=', 'position_versions.position_id')
            ->select('position_versions.*', DB::raw('coalesce(occupancy.occupied_seats, 0) as occupied_seats'), DB::raw('coalesce(occupancy.occupied_fte, 0) as occupied_fte'));
    }

    /** Open positions on a day with unfilled seats — vacancies are capacity facts, never requisitions. */
    public function vacancies(CarbonInterface|string|null $on = null): Builder
    {
        return $this->positionsOn($on)->where('position_versions.status', 'open')
            ->whereRaw('coalesce(occupancy.occupied_seats, 0) < position_versions.headcount');
    }

    private function aggregate(string $day, ?string $dimension): Builder
    {
        $dimensionSql = $dimension ? 'coalesce(position_versions.'.$dimension.', \'\')' : "''";

        return $this->versionsOn($day)
            ->leftJoinSub($this->occupancySubquery($day), 'occupancy', 'occupancy.position_id', '=', 'position_versions.position_id')
            ->groupBy('position_versions.status')
            ->when($dimension, fn ($q) => $q->groupBy('position_versions.'.$dimension))
            ->selectRaw("position_versions.status as status, {$dimensionSql} as dimension_id, count(*) as positions, sum(position_versions.headcount) as seats, sum(position_versions.fte_capacity) as fte_capacity,
                sum(coalesce(occupancy.occupied_seats, 0)) as occupied_seats, sum(coalesce(occupancy.occupied_fte, 0)) as occupied_fte,
                sum(case when coalesce(occupancy.occupied_seats, 0) < position_versions.headcount then position_versions.headcount - coalesce(occupancy.occupied_seats, 0) else 0 end) as unfilled_seats,
                sum(case when coalesce(occupancy.occupied_fte, 0) < position_versions.fte_capacity then position_versions.fte_capacity - coalesce(occupancy.occupied_fte, 0) else 0 end) as unfilled_fte");
    }
}
