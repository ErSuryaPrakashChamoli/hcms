<?php

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Models\SalaryStructureVersion;
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

        // Version 1, in force since 2000-01-01 ("before PeopleOS"), like the versions the Phase 11
        // migration gave existing structures. Written directly: tenant provisioning has no approver.
        if ($structure->versions()->doesntExist()) {
            $version = SalaryStructureVersion::query()->create(['salary_structure_id' => $structure->id, 'version' => 1, 'status' => 'draft', 'effective_from' => '2000-01-01', 'currency' => config('peopleos.settings.tenant.base_currency', 'INR'), 'pay_frequency' => 'monthly', 'change_note' => 'Standard structure provisioned with the tenant.']);
            foreach (['BASIC', 'HRA', 'CONV', 'SPECIAL'] as $i => $code) {
                $component = SalaryComponent::query()->where('code', $code)->first();
                if ($component) {
                    $version->components()->create(['salary_structure_id' => $structure->id, 'salary_component_id' => $component->id, 'sort_order' => ($i + 1) * 10]);
                }
            }
            $version->update(['status' => 'active', 'approved_at' => now()]);
        }

        return $structure;
    }
}
