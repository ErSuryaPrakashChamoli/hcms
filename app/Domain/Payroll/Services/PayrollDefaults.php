<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Payroll\Models\SalaryComponent;

/** Starter payroll components every tenant receives (§30). The "Standard" structure is Compensation's (CompensationDefaults). */
final class PayrollDefaults
{
    public function seed(): void
    {
        foreach (config('peopleos.payroll.defaults', []) as $row) {
            SalaryComponent::query()->firstOrCreate(['code' => $row['code']], $row + ['status' => 'active', 'is_recurring' => true, 'is_arrear_eligible' => true]);
        }
    }
}
