<?php

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Models\WorkforcePlanLine;
use App\Domain\Workforce\Models\WorkforcePlanVersion;

/**
 * Phase 11 §9 / §17: compensation planning against Phase 10 positions and workforce plans — read-only.
 *
 *   Workforce plan ─► compensation planning (here: priced at the applicable range) ─► approval ─►
 *   explicit action (proposeFromPosition) ─► a compensation change that still needs review, approval
 *   and execution ─► live compensation.
 *
 * Nothing here writes a plan, a position or an employee: a position's grade, role and organisation
 * only select the applicable range. Position ≠ compensation: two people in one position can be paid
 * differently.
 */
final class CompensationPlanning
{
    public function __construct(private readonly CompensationRanges $ranges, private readonly CompensationChanges $changes) {}

    /**
     * Price every line of a plan version at the applicable range (annual CTC, the line's currency is
     * the range's). Lines without a grade, or without an approved range, are reported, never guessed.
     *
     * @return array{lines: list<array<string, mixed>>, totals: array<string, array{minimum: float, midpoint: ?float, maximum: float, headcount: int}>, unpriced: int}
     */
    public function priceVersion(WorkforcePlanVersion $version): array
    {
        $lines = WorkforcePlanLine::query()->where('workforce_plan_version_id', $version->id)->with('position.currentVersion')->orderBy('id')->get();
        $out = [];
        $totals = [];
        $unpriced = 0;
        foreach ($lines as $line) {
            $sign = $line->sign();
            $positionVersion = $line->position?->versionOn($line->effective_date) ?? $line->position?->currentVersion;
            $gradeId = $line->grade_id ?? $positionVersion?->grade_id;
            $range = $gradeId ? $this->ranges->rangeFor((int) $gradeId, $line->effective_date, $positionVersion?->company_id ?? $version->plan?->company_id, $line->job_family_id ?? $positionVersion?->job_family_id, $line->designation_id ?? $positionVersion?->designation_id) : null;
            $row = ['line_id' => $line->id, 'movement' => $line->movement_type, 'sign' => $sign, 'headcount' => (int) $line->headcount, 'grade_id' => $gradeId, 'range_id' => $range?->id,
                'currency' => $range?->currency, 'basis' => 'annual_ctc', 'minimum' => null, 'midpoint' => null, 'maximum' => null,
                'planned_cost' => $line->planned_cost !== null ? (float) $line->planned_cost : null, 'planned_cost_basis' => $line->cost_basis];
            if ($range === null) {
                $row['note'] = $gradeId ? 'No approved range for this grade on the line date.' : 'The line has no grade (directly or through its position).';
                $unpriced++;
            } else {
                $n = $sign * (int) $line->headcount;
                $row['minimum'] = round($n * $range->annual('minimum'), 2);
                $row['midpoint'] = $range->midpoint === null ? null : round($n * $range->annual('midpoint'), 2);
                $row['maximum'] = round($n * $range->annual('maximum'), 2);
                $t = &$totals[$range->currency];
                $t ??= ['minimum' => 0.0, 'midpoint' => 0.0, 'maximum' => 0.0, 'headcount' => 0, 'midpoint_missing' => 0];
                $t['minimum'] += $row['minimum'];
                $t['maximum'] += $row['maximum'];
                $t['headcount'] += $n;
                $row['midpoint'] === null ? $t['midpoint_missing']++ : $t['midpoint'] += $row['midpoint'];
                unset($t);
            }
            $out[] = $row;
        }

        return ['lines' => $out, 'totals' => array_map(fn ($t) => ['minimum' => round($t['minimum'], 2), 'midpoint' => $t['midpoint_missing'] ? null : round($t['midpoint'], 2), 'maximum' => round($t['maximum'], 2), 'headcount' => $t['headcount']], $totals), 'unpriced' => $unpriced];
    }

    /**
     * The explicit action from planning to people: a draft compensation change for an employee who
     * holds (or is moving to) a position, prefilled from the position's applicable range. It changes no
     * position, grade or organisation; it still needs review, approval and execution.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function proposeFromPosition(Employee $employee, Position $position, User $actor, array $overrides = []): CompensationChange
    {
        $date = $overrides['effective_from'] ?? now()->toDateString();
        $definition = $position->versionOn($date);
        if ($definition === null || $definition->grade_id === null) {
            throw new CompensationRuleViolation('The position has no grade on that date, so no range applies.');
        }
        $range = $this->ranges->rangeFor((int) $definition->grade_id, $date, $definition->company_id, $definition->job_family_id, $definition->designation_id);
        if ($range === null) {
            throw new CompensationRuleViolation('No approved range applies to the position on that date.');
        }
        $current = EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->active()->where('employee_id', $employee->id)->effectiveOn($date)->first();
        $held = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->effectiveOn($date)->orderByDesc('effective_from')->first();

        return $this->changes->propose($employee, $overrides + [
            'change_type' => (int) ($held?->position_id) === (int) $position->id ? 'role_grade_adjustment' : 'promotion',
            'effective_from' => $date, 'salary_structure_id' => $current?->salary_structure_id ?? $overrides['salary_structure_id'] ?? null,
            'ctc_annual' => $range->annual('midpoint') ?? $range->annual('minimum'), 'currency' => $range->currency,
            'component_values' => $current?->component_values ?? [],
            'from_grade_id' => $held?->grade_id, 'to_grade_id' => $definition->grade_id,
            'from_position_id' => $held?->position_id, 'to_position_id' => $position->id,
            'reason' => 'Proposed from position '.$position->code.' (range '.$range->currency.' '.number_format((float) $range->annual('minimum')).'–'.number_format((float) $range->annual('maximum')).')',
        ], $actor, 'position');
    }
}
