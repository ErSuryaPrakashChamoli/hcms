<?php

namespace App\Domain\Payroll\Contracts;

use App\Domain\Employment\Models\Employee;

/**
 * HROne setup parity (PS-3): read contract for what an employee was last paid, from finalized or paid payroll only.
 * Self-service pages outside Payroll (My compensation) read it here instead of payroll entries.
 */
interface PaidPayReader
{
    /**
     * Net, gross and wages of the employee's latest finalized or paid payroll entry; wages are the earnings classified
     * as wages for the wage-share guardrail, exclusions the other earnings.
     *
     * @return array{net: float, gross: float, wages: float, exclusions: float, wage_share: float, period: string}|null
     */
    public function latestPaid(Employee $employee): ?array;
}
