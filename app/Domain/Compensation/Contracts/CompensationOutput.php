<?php

namespace App\Domain\Compensation\Contracts;

use App\Domain\Compensation\Support\CompensationSnapshot;
use App\Domain\Compensation\Support\PayrollCompensation;
use App\Domain\Employment\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Phase 11: the read contract every other module uses for employee compensation. Read-only.
 *
 * It returns only the canonical timeline: active employee_salary_assignments rows, each written by
 * executing an approved compensation change (or carried over from before Phase 11). Draft, submitted,
 * rejected and cancelled changes have no canonical row, so they can never be returned. Payroll
 * consumes forPayroll(); Letters and Exit consume on(); nothing outside Compensation reads or writes
 * the assignment table directly.
 */
interface CompensationOutput
{
    /** Version of the shape returned to consumers (recorded by Payroll on every entry). */
    public const VERSION = 'compensation-output-1';

    /** getCompensation(employee, date): the approved compensation in force on the date, or null. */
    public function on(Employee $employee, CarbonInterface|string|null $date = null): ?CompensationSnapshot;

    /**
     * getCompensationForPayroll(employee, period): every approved compensation segment overlapping
     * [from, to], each clipped to the window, with the components of its structure and a fingerprint
     * of the rows used (Payroll re-checks it before finalisation).
     */
    public function forPayroll(Employee $employee, CarbonInterface $from, CarbonInterface $to): PayrollCompensation;

    /**
     * Fingerprints of the approved compensation overlapping [from, to] for many employees, in one
     * query. Integrity check only (no amounts), so it is not limited by the reader's organisation scope.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, string> employee id => fingerprint
     */
    public function fingerprints(array $employeeIds, CarbonInterface $from, CarbonInterface $to): array;

    /**
     * The approved history of one employee, oldest first (rows corrected or cancelled before they took
     * effect are not compensation the employee had, and are left out).
     *
     * @return Collection<int, CompensationSnapshot>
     */
    public function history(Employee|int $employee): Collection;

    /** Sub-select of employee ids with approved compensation in force on the date (for readiness counts). */
    public function coveredEmployeeIds(CarbonInterface|string|null $date = null): Builder;

    /**
     * Annual CTC in force on the date for the employees the current user may reach, in one query.
     *
     * @param  list<int>|null  $employeeIds  null = every reachable employee
     * @return array<int, float> employee id => annual CTC
     */
    public function annualCtcOn(?array $employeeIds = null, CarbonInterface|string|null $date = null): array;
}
