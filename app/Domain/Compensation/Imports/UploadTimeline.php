<?php

namespace App\Domain\Compensation\Imports;

use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Contracts\PayrollClosureReader;
use Illuminate\Support\Carbon;

/**
 * The timeline rules AssignmentWriter would refuse at execution, checked when a compensation upload is validated (the
 * salary upload and the Data import DI-4 uploads that prepare compensation cycles): the employee in the cycle's company
 * on the date, no other open change, compensation already starting on the date (identical → skip; different → needs a
 * correction), payroll finalized on or after the date. One stale row then cannot block the cycle's all-or-none execution.
 */
final class UploadTimeline
{
    public function __construct(private readonly PayrollClosureReader $closure) {}

    /**
     * What execution would do with this proposal: refuse it (error), find it already in force (skip) or write it (create).
     *
     * @param  array<string, mixed>  $content  normalised by CompensationChanges::check
     * @return array{0: string, 1: ?string} action and the reason for an error or a skip
     */
    public function against(Employee $employee, array $content, int $companyId): array
    {
        $date = Carbon::parse($content['effective_from']);
        $held = $this->companyOn($employee, $date);
        if ($held !== $companyId) {
            return ['error', "Employee {$employee->employee_code} does not work for ".Company::query()->whereKey($companyId)->value('name')." on {$date->toDateString()}; upload other companies separately."];
        }

        // A decision still open for the employee (or a line of another cycle not yet submitted) would make
        // one of the two stale at approval.
        $pending = CompensationChange::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)
            ->where(fn ($q) => $q->whereIn('status', [...CompensationChange::PENDING, 'approved'])->orWhere(fn ($q) => $q->where('status', 'draft')->whereNotNull('compensation_cycle_id')))
            ->value('reference');
        if ($pending !== null) {
            return ['error', "Compensation change {$pending} for this employee is still open; finish or cancel it first."];
        }

        $same = EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->active()->where('employee_id', $employee->id)
            ->where('effective_from', '>=', $date->toDateString())->where('effective_from', '<=', $date->toDateString().' 23:59:59')->first();
        if ($same !== null) {
            $values = fn (?array $v) => collect($v ?? [])->map(fn ($a) => round((float) $a, 2))->sortKeys()->all();
            $identical = (int) $same->salary_structure_id === (int) $content['salary_structure_id'] && round((float) $same->ctc_annual, 2) === $content['ctc_annual']
                && $same->currency === $content['currency'] && $values($same->component_values) === $values($content['component_values'])
                && ($same->variable_target_annual === null ? null : round((float) $same->variable_target_annual, 2)) === $content['variable_target_annual'];
            if ($identical) {
                return ['skip', "Already in force from {$date->toDateString()}."];
            }
            if ($content['change_type'] !== 'correction') {
                return ['error', "Compensation already starts on {$date->toDateString()}; set Change type to correction to replace it, or use another date."];
            }
        }

        $closed = $this->closure->closedOnOrAfter((int) $employee->id, $date);
        if ($closed !== null) {
            return ['error', 'Payroll is finalized through '.$closed->toDateString().'; use an effective date after it and pay the difference as an arrear.'];
        }

        return ['create', null];
    }

    /** The company the employee works for on the date (their position then), or null. */
    public function companyOn(Employee $employee, Carbon|string $date): ?int
    {
        $company = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->effectiveOn(Carbon::parse($date))->orderByDesc('effective_from')->value('company_id');

        return $company === null ? null : (int) $company;
    }

    /** Whether the employee has compensation on file (an active salary assignment). */
    public function onFile(Employee $employee): bool
    {
        return EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->active()->where('employee_id', $employee->id)->exists();
    }

    /** Whether the employee has compensation starting on or before the date (a revision rather than a first CTC). */
    public function hasEarlier(Employee $employee, string $date): bool
    {
        return EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->active()->where('employee_id', $employee->id)->where('effective_from', '<=', $date.' 23:59:59')->exists();
    }
}
