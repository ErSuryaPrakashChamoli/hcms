<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Payroll\Models\EmployeeSalaryAssignment;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryStructure;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Salary assignment and revision with history (§70, §100). */
final class Salaries
{
    public function __construct(private readonly AuditRecorder $audit, private readonly Timeline $timeline) {}

    public function current(Employee $employee, CarbonInterface|string|null $on = null): ?EmployeeSalaryAssignment
    {
        return EmployeeSalaryAssignment::query()
            ->where('employee_id', $employee->id)
            ->effectiveOn($on)
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->first();
    }

    /** @param  array<string, float>  $componentValues  monthly fixed amounts keyed by component code */
    public function assign(Employee $employee, SalaryStructure $structure, float $ctcAnnual, CarbonInterface|string $effectiveFrom, array $componentValues = [], string $changeType = 'hire', ?string $reason = null, ?User $actor = null, string $currency = 'INR'): EmployeeSalaryAssignment
    {
        $from = Carbon::parse($effectiveFrom)->startOfDay();

        // A revision may not reach back into a period this employee was already paid for (Phase 4 §50).
        $closedThrough = PayrollPeriod::query()
            ->whereIn('id', PayrollRun::query()->whereIn('status', ['finalized', 'paid'])->whereIn('id', PayrollEntry::query()->where('employee_id', $employee->id)->select('payroll_run_id'))->select('payroll_period_id'))
            ->max('end_date');
        if ($closedThrough !== null && $from->lte(Carbon::parse($closedThrough))) {
            throw new RuntimeException('Payroll is finalized through '.Carbon::parse($closedThrough)->toDateString().'; a salary effective on '.$from->toDateString().' would rewrite a closed payroll period. Use an effective date after it and pay the difference as an arrear.');
        }

        if ($ctcAnnual <= 0) {
            throw new RuntimeException('Annual CTC must be positive.');
        }

        if ($structure->status->value !== 'active') {
            throw new RuntimeException('The salary structure is not active.');
        }

        return DB::transaction(function () use ($employee, $structure, $ctcAnnual, $from, $componentValues, $changeType, $reason, $actor, $currency) {
            $previous = $this->current($employee, $from);

            if ($previous && $previous->effective_from->gte($from)) {
                throw new RuntimeException('An assignment already starts on or after '.$from->toDateString().'. Use a later effective date or a correction.');
            }

            $previous?->update(['effective_to' => $from->copy()->subDay()]);

            // Any assignment starting later than the new one is dropped: it would shadow it.
            EmployeeSalaryAssignment::query()->where('employee_id', $employee->id)->where('effective_from', '>', $from->toDateString())->delete();

            $assignment = EmployeeSalaryAssignment::create([
                'employee_id' => $employee->id,
                'salary_structure_id' => $structure->id,
                'ctc_annual' => round($ctcAnnual, 2),
                'currency' => $currency,
                'component_values' => collect($componentValues)->mapWithKeys(fn ($v, $k) => [strtoupper($k) => round((float) $v, 2)])->all(),
                'change_type' => $changeType,
                'effective_from' => $from,
                'effective_to' => null,
                'reason' => $reason,
                'created_by' => $actor?->id ?? auth()->id(),
            ]);

            $this->audit->record(AuditAction::SalaryChanged, 'payroll', $assignment, [
                ['field' => 'ctc_annual', 'before' => $previous?->ctc_annual, 'after' => $assignment->ctc_annual, 'sensitive' => true],
                ['field' => 'salary_structure', 'before' => $previous?->structure()->value('code'), 'after' => $structure->code],
            ], $reason, effectiveDate: $from, metadata: ['change_type' => $changeType, 'employee_id' => $employee->id]);

            $this->timeline->record($employee, 'compensation', ucfirst($changeType).': salary '.($previous ? 'revised' : 'assigned'), $from, $reason, $assignment, ['structure' => $structure->code]);
            EmploymentEvent::dispatch('employee.salary_changed', $employee, $assignment, ['change_type' => $changeType, 'structure' => $structure->code, 'effective_date' => $from->toDateString()]);

            return $assignment;
        });
    }
}
