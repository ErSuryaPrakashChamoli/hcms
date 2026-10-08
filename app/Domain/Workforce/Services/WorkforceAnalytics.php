<?php

namespace App\Domain\Workforce\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Organisation\Models\Location;
use App\Domain\Succession\Models\CriticalPosition;
use App\Domain\Workforce\Models\WorkforceBudget;
use App\Domain\Workforce\Models\WorkforcePlan;
use App\Domain\Workforce\Models\WorkforcePlanVersion;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Phase 10 workforce analytics: positions, seats, FTE, occupancy and vacancy by organisation,
 * location, job family and employment type; planned against actual capacity for active plans;
 * budget against actual cost (workforce.costs only, small populations suppressed); succession
 * coverage of critical roles. Database aggregates under the caller's organisation scope. Facts and
 * labelled assumptions only — nobody is ranked, scored or predicted.
 */
final class WorkforceAnalytics
{
    public function __construct(
        private readonly WorkforceSnapshot $snapshot,
        private readonly WorkforcePlans $plans,
        private readonly WorkforceBudgets $budgets,
    ) {}

    public function minGroup(): int
    {
        return max(1, (int) config('peopleos.workforce.analytics_min_group', 5));
    }

    /** @return array<string, mixed> */
    public function summary(User $viewer, CarbonInterface|string|null $on = null): array
    {
        $day = Carbon::parse($on ?? now())->toDateString();

        return [
            'date' => $day,
            'headcount' => $this->snapshot->headcount($day),
            'by_department' => $this->named($this->snapshot->by('department_id', $day), Department::class),
            'by_location' => $this->named($this->snapshot->by('location_id', $day), Location::class),
            'by_job_family' => $this->named($this->snapshot->by('job_family_id', $day), JobFamily::class),
            'by_employment_type' => $this->named($this->snapshot->by('employment_type_id', $day), EmploymentType::class),
            'plans' => $this->plannedVsActual($viewer, $day),
            'budgets' => $viewer->hasPermission('workforce.costs') ? $this->budgetVsActual($viewer, $day) : null,
            'critical_positions' => CriticalPosition::query()->where('status', 'active')->count(),
        ];
    }

    /** Active plan versions: net planned capacity against the approved and occupied seats in the plan's scope today. */
    public function plannedVsActual(User $viewer, string $day): array
    {
        return WorkforcePlan::query()->whereNotNull('active_version_id')->with('activeVersion')->orderBy('code')->limit(50)->get()
            ->map(function (WorkforcePlan $plan) use ($viewer, $day) {
                $version = $plan->activeVersion;
                if (! $version instanceof WorkforcePlanVersion) {
                    return null;
                }
                $scope = collect($plan->getAttributes())->only(['company_id', 'business_unit_id', 'division_id', 'department_id', 'team_id', 'location_id', 'establishment_id'])->all();
                $now = $this->snapshot->headcount($day, $scope);
                $totals = $this->plans->totals($version, $viewer);

                return [
                    'plan' => $plan->code, 'name' => $plan->name, 'version' => $version->version, 'period' => $version->period_start->toDateString().' – '.$version->period_end->toDateString(),
                    'planned_net_change' => $totals['headcount'], 'planned_net_fte' => $totals['fte'],
                    'approved_seats' => $now['approved_seats'], 'occupied_seats' => $now['occupied_seats'], 'vacant_seats' => $now['vacant_seats'],
                ];
            })->filter()->values()->all();
    }

    /** Approved budgets covering the day, compared with planned and actual cost on the same basis. */
    public function budgetVsActual(User $viewer, string $day): array
    {
        return WorkforceBudget::query()->where('status', 'approved')->whereDate('period_start', '<=', $day)->whereDate('period_end', '>=', $day)->orderBy('name')->limit(50)->get()
            ->map(fn (WorkforceBudget $b) => ['name' => $b->name, ...$this->budgets->comparison($b, $viewer)])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  class-string  $model
     */
    private function named(array $rows, string $model): array
    {
        $names = $model::query()->whereIn('id', array_filter(array_column($rows, 'id')))->pluck('name', 'id');

        return array_map(fn ($row) => ['name' => $row['id'] ? ($names[$row['id']] ?? '#'.$row['id']) : 'Not set', ...$row], $rows);
    }
}
