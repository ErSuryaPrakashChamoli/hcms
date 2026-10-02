<?php

namespace App\Domain\Compensation\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Payroll\Contracts\PayrollClosureReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Phase 11 — the internal executor and the only writer of employee_salary_assignments.
 *
 * @internal Called only by CompensationChanges, inside its transaction, after it has locked an
 * approved change and checked separation of duties. It has no method that takes raw amounts: every
 * row it writes comes from an approved CompensationChange.
 *
 * Timeline rules (inclusive dates; the active rows of an employee never overlap):
 *  - the payroll runs that could be affected are locked (PayrollClosureReader), then the employee row,
 *    then the employee's active rows (locking reads: REPEATABLE READ must not hide a concurrent write);
 *    finalisation takes the run row and then (through its payslips' foreign key) the employee row, so
 *    the same order avoids deadlocks;
 *  - a date inside a finalized / paid payroll period is refused (pay the difference as an arrear);
 *  - the compensation in force on the effective date must still be the one the change was approved
 *    against, otherwise the change goes back for a new decision;
 *  - a row already starting on the effective date is refused, unless the change is a correction, which
 *    supersedes that row (kept, marked superseded);
 *  - the previous row is closed the day before; the new row ends the day before the next active row
 *    (a later, already scheduled compensation is kept, never deleted) or stays open.
 */
final class AssignmentWriter
{
    private static int $writing = 0;

    public function __construct(private readonly PayrollClosureReader $closure, private readonly AuditRecorder $audit, private readonly Timeline $timeline) {}

    /** True only while this writer is changing the canonical table (checked by the model guard). */
    public static function isWriting(): bool
    {
        return self::$writing > 0;
    }

    public function write(CompensationChange $change, User $executor): EmployeeSalaryAssignment
    {
        $this->assertInTransaction();
        if ($change->status !== 'approved') {
            throw new CompensationRuleViolation('Only an approved compensation change is executed.');
        }
        $change->loadMissing(['structure:id,code', 'previousStructure:id,code']);

        // Lock order matches payroll finalisation (run row, then the employee row its payslips reference),
        // so a compensation write and a finalisation wait for each other instead of deadlocking.
        $from = $change->effective_from->copy()->startOfDay();
        $this->assertPayrollOpen((int) $change->employee_id, $from);
        $employee = $this->lockEmployee((int) $change->employee_id);

        $rows = $this->activeRows($employee);
        $inForce = $rows->first(fn (EmployeeSalaryAssignment $r) => $r->isEffectiveOn($from));
        if (($inForce?->id) !== ($change->previous_assignment_id ? (int) $change->previous_assignment_id : null)) {
            throw new CompensationRuleViolation('The compensation in force on '.$from->toDateString().' has changed since this change was approved; return it for a new decision.');
        }

        $same = $rows->first(fn (EmployeeSalaryAssignment $r) => $r->effective_from->equalTo($from));
        if ($same && $change->change_type !== 'correction') {
            throw new CompensationRuleViolation('An assignment already starts on '.$from->toDateString().'. Use a later effective date or a correction.');
        }
        $previous = $rows->filter(fn (EmployeeSalaryAssignment $r) => $r->effective_from->lt($from))->last();
        $next = $rows->first(fn (EmployeeSalaryAssignment $r) => $r->effective_from->gt($from));
        $to = $next ? $next->effective_from->copy()->subDay() : $same?->effective_to;

        return $this->writing(function () use ($change, $executor, $employee, $from, $to, $same, $previous) {
            if ($same) {
                // Free the start date first: one active row per employee and start date (active_key).
                $same->withAuditAction(AuditAction::Corrected)->withAuditReason('Corrected by compensation change '.$change->reference)
                    ->update(['status' => 'superseded', 'active_key' => null]);
            }
            if ($previous && ($previous->effective_to === null || $previous->effective_to->gte($from))) {
                $previous->update(['effective_to' => $from->copy()->subDay()]);
            }

            $row = EmployeeSalaryAssignment::query()->create([
                'employee_id' => $employee->id,
                'compensation_change_id' => $change->id,
                'salary_structure_id' => $change->salary_structure_id,
                'ctc_annual' => $change->ctc_annual,
                'currency' => $change->currency,
                'pay_frequency' => $change->pay_frequency,
                'variable_target_annual' => $change->variable_target_annual,
                'component_values' => $change->component_values ?? [],
                'change_type' => $change->change_type,
                'status' => 'active',
                'active_key' => 1,
                'effective_from' => $from,
                'effective_to' => $to,
                'reason' => mb_substr((string) $change->reason, 0, 255),
                'created_by' => $executor->id,
                'approved_by' => $change->approved_by,
                'approved_at' => $change->approved_at,
            ]);
            $same?->update(['superseded_by_id' => $row->id]);

            $this->audit->record($same ? AuditAction::Corrected : AuditAction::SalaryChanged, 'compensation', $row, [
                ['field' => 'ctc_annual', 'before' => $change->previous_ctc_annual, 'after' => $row->ctc_annual, 'sensitive' => true],
                ['field' => 'salary_structure', 'before' => $change->previousStructure?->code, 'after' => $change->structure?->code],
            ], null, actor: $executor, effectiveDate: $from, metadata: ['change' => $change->reference, 'change_type' => $change->change_type, 'employee_id' => $employee->id, 'supersedes' => $same?->id]);
            // No amounts or reasons on the timeline: it is visible beyond compensation readers.
            $this->timeline->record($employee, 'compensation', ($same ? 'Compensation corrected' : 'Compensation changed').' ('.$change->typeLabel().')', $from, null, $row, ['structure' => $change->structure?->code, 'change' => $change->reference]);
            EmploymentEvent::dispatch('employee.salary_changed', $employee, $row, ['change_type' => $change->change_type, 'structure' => $change->structure?->code, 'effective_date' => $from->toDateString()]);

            return $row;
        });
    }

    /**
     * A scheduled change cancelled before its effective date: its row stays as history (cancelled), a row
     * it had superseded becomes active again, and the previous row closes around the remaining timeline.
     */
    public function cancel(EmployeeSalaryAssignment $row, CompensationChange $change, User $actor): void
    {
        $this->assertInTransaction();
        // Same lock order as write(): payroll runs, then the employee, then the rows. The caller only
        // cancels a change the effective-date processor has not yet made effective; a payroll period
        // already finalized over its dates is never rewritten.
        $this->assertPayrollOpen((int) $row->employee_id, $row->effective_from->copy()->startOfDay());
        $employee = $this->lockEmployee((int) $row->employee_id);
        $row = EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->whereKey($row->id)->lockForUpdate()->firstOrFail();
        if (! $row->isActive()) {
            throw new CompensationRuleViolation('That compensation is no longer part of the timeline.');
        }

        $rows = $this->activeRows($employee);
        $restored = EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->where('superseded_by_id', $row->id)->lockForUpdate()->first();

        $this->writing(function () use ($row, $rows, $restored, $change) {
            $row->withAuditAction(AuditAction::Cancelled)->withAuditReason('Compensation change '.$change->reference.' cancelled')->update(['status' => 'cancelled', 'active_key' => null]);
            if ($restored) {
                // The corrected row returns with the cancelled row's end (rows added after it still follow).
                $restored->withAuditReason('Correction '.$change->reference.' cancelled')->update(['status' => 'active', 'active_key' => 1, 'superseded_by_id' => null, 'effective_to' => $row->effective_to]);

                return;
            }
            $previous = $rows->filter(fn (EmployeeSalaryAssignment $r) => $r->effective_from->lt($row->effective_from))->last();
            if ($previous && $previous->effective_to?->equalTo($row->effective_from->copy()->subDay())) {
                $previous->update(['effective_to' => $row->effective_to]);
            }
        });
    }

    /** @return Collection<int, EmployeeSalaryAssignment> the employee's active rows, oldest first, locked */
    private function activeRows(Employee $employee): Collection
    {
        return EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)
            ->where('employee_id', $employee->id)->where('status', 'active')
            ->orderBy('effective_from')->orderBy('id')->lockForUpdate()->get();
    }

    private function lockEmployee(int $employeeId): Employee
    {
        return Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employeeId)->lockForUpdate()->firstOrFail();
    }

    private function assertPayrollOpen(int $employeeId, Carbon $from): void
    {
        $closedThrough = $this->closure->closedOnOrAfter($employeeId, $from);
        if ($closedThrough !== null) {
            throw new CompensationRuleViolation('Payroll is finalized through '.Carbon::parse($closedThrough)->toDateString().'; a salary effective on '.$from->toDateString().' would rewrite a closed payroll period. Use an effective date after it and pay the difference as an arrear.');
        }
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The compensation writer runs inside the transaction that locked the change.');
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function writing(callable $callback): mixed
    {
        self::$writing++;
        try {
            return $callback();
        } finally {
            self::$writing--;
        }
    }
}
