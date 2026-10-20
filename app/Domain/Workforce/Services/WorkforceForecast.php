<?php

namespace App\Domain\Workforce\Services;

use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Workforce\Models\PositionVersion;
use App\Domain\Workforce\Models\WorkforcePlanLine;
use App\Domain\Workforce\Models\WorkforcePlanVersion;
use Illuminate\Support\Carbon;

/**
 * Phase 10 forecast for a plan version, month by month over its period. Built only from facts —
 * capacity and occupancy at the start, the plan's dated movements, exits already recorded (open exit
 * cases with a last working day) — plus the scenario's explicit attrition percentage, which is shown
 * separately and labelled "planning assumption". Nobody is predicted to leave; no individual score.
 */
final class WorkforceForecast
{
    public function __construct(private readonly WorkforceSnapshot $snapshot) {}

    /** @return array{basis: array<string, mixed>, months: list<array<string, mixed>>} */
    public function forPlan(WorkforcePlanVersion $version): array
    {
        $plan = $version->plan()->withoutGlobalScope(AccessScope::class)->firstOrFail();
        $scope = collect($plan->getAttributes())->only(['company_id', 'business_unit_id', 'division_id', 'department_id', 'team_id', 'location_id', 'establishment_id'])->all();
        $start = $version->period_start->copy()->startOfMonth();
        $baseline = $this->snapshot->headcount($version->period_start, $scope);
        $rate = $version->scenario?->assumptions['attrition_rate_percent'] ?? null;

        $movements = WorkforcePlanLine::query()->where('workforce_plan_version_id', $version->id)
            ->selectRaw('movement_type, effective_date, sum(headcount) as headcount')->groupBy('movement_type', 'effective_date')->get()
            ->groupBy(fn ($r) => Carbon::parse($r->effective_date)->format('Y-m'))
            ->map(fn ($rows) => (int) $rows->sum(fn ($r) => (int) config("peopleos.workforce.movement_types.{$r->movement_type}.sign", 0) * (int) $r->headcount));

        // Occupants of positions in scope today whose exit is already recorded within the period.
        $positionIds = $this->snapshot->withinScope(PositionVersion::query()->withoutGlobalScope(AccessScope::class)->effectiveOn(now()->toDateString()), $scope)->select('position_versions.position_id');
        $occupants = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)
            ->whereIn('position_id', $positionIds)->whereNull('effective_to')->select('employee_id');
        $exits = ExitCase::query()->withoutGlobalScope(AccessScope::class)->whereIn('employee_id', $occupants)->whereIn('status', ExitCase::OPEN)
            ->whereDate('last_working_day', '>=', $version->period_start->toDateString())->whereDate('last_working_day', '<=', $version->period_end->toDateString())
            ->pluck('last_working_day')->countBy(fn ($d) => Carbon::parse($d)->format('Y-m'));

        $months = [];
        $planned = $baseline['approved_seats'];
        $occupied = $baseline['occupied_seats'];
        $assumed = 0.0;
        for ($month = $start->copy(); $month->lte($version->period_end); $month->addMonthNoOverflow()) {
            $key = $month->format('Y-m');
            $planned += $movements[$key] ?? 0;
            $occupied -= $exits[$key] ?? 0;
            $monthAssumption = $rate === null ? null : round($baseline['occupied_seats'] * ((float) $rate / 100) / 12, 1);
            $assumed += $monthAssumption ?? 0;
            $months[] = [
                'month' => $key, 'planned_change' => $movements[$key] ?? 0, 'planned_seats' => $planned,
                'recorded_exits' => $exits[$key] ?? 0, 'occupied_after_recorded_exits' => max(0, $occupied),
                'assumed_attrition' => $monthAssumption, 'occupied_after_assumption' => $rate === null ? null : max(0, round($occupied - $assumed, 1)),
            ];
        }

        return [
            'basis' => [
                'start_date' => $version->period_start->toDateString(), 'approved_seats' => $baseline['approved_seats'], 'occupied_seats' => $baseline['occupied_seats'],
                'attrition_assumption_percent' => $rate, 'assumption_label' => 'Planning assumption (entered on the scenario) — not a prediction about any employee',
            ],
            'months' => $months,
        ];
    }
}
