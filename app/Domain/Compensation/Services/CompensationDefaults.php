<?php

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Payroll\Models\SalaryComponent;

/**
 * The "Standard" compensation structure every tenant receives (§30), built from Payroll's starter
 * components (PayrollDefaults seeds those first). Phase 11: structures are Compensation's.
 */
final class CompensationDefaults
{
    public function seed(): SalaryStructure
    {
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
