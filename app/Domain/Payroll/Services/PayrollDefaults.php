<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\SalaryStructure;

/** Starter components and the "Standard" structure every tenant receives (§30). */
final class PayrollDefaults
{
    public function seed(): SalaryStructure
    {
        foreach (config('peopleos.payroll.defaults', []) as $row) {
            SalaryComponent::query()->firstOrCreate(['code' => $row['code']], $row + ['status' => 'active', 'is_recurring' => true, 'is_arrear_eligible' => true]);
        }

        $structure = SalaryStructure::query()->firstOrCreate(['code' => 'STANDARD'], ['name' => 'Standard structure', 'description' => 'Basic 40% of CTC, HRA 50% of basic, conveyance, special allowance balancing to CTC.', 'status' => 'active']);

        if ($structure->items()->doesntExist()) {
            foreach (['BASIC', 'HRA', 'CONV', 'SPECIAL'] as $i => $code) {
                $component = SalaryComponent::query()->where('code', $code)->first();
                if ($component) {
                    $structure->items()->create(['salary_component_id' => $component->id, 'sort_order' => ($i + 1) * 10]);
                }
            }
        }

        return $structure;
    }
}
