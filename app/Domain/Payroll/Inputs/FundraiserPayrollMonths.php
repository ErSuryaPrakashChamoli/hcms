<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Engagement\Contracts\PledgePayroll;

/**
 * HROne parity GO-18 (D-GO3): the payroll months a fundraiser pledge may be deducted in, read exactly as expense reports
 * read them (ExpensePayrollMonths: the pay cycle and the company's payroll months, never created or changed here), and
 * the pledges a payroll run being prepared already deducts (PendingPayrollInputs, key `fundraiser_pledge`).
 */
final class FundraiserPayrollMonths implements PledgePayroll
{
    public function __construct(private readonly ExpensePayrollMonths $months, private readonly PendingPayrollInputs $pending) {}

    public function months(int $employeeId): array
    {
        return $this->months->openFor($employeeId);
    }

    public function upcoming(): array
    {
        return $this->months->upcoming();
    }

    public function carried(int $employeeId): array
    {
        return $this->pending->pending(FundraiserDeductionInput::KEY, $employeeId);
    }
}
