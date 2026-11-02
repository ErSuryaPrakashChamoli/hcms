<?php

namespace App\Domain\Payroll\Contracts;

use App\Domain\Employment\Models\Employee;

/**
 * HROne parity WO-11 (§23.6.1 #14, "Salary hold since / Salary hold amount"): read contract for the salary an employee
 * still has on hold (HROne parity PY-06), for modules outside Payroll (the exit case's relieving review). Read only.
 */
interface SalaryHoldReader
{
    /**
     * Holds not yet released, oldest pay month first. The net pay of the month comes from a finalized or paid payroll
     * entry of that month (null while the month is not finalized).
     *
     * @return list<array{pay_month: string, reason: string, held_on: ?string, net: ?float}>
     */
    public function openHolds(Employee $employee): array;
}
