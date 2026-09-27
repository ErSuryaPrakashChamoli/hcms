<?php

namespace App\Domain\Compliance\Services\Returns;

use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Models\StatutorySnapshot;
use App\Domain\Payroll\Models\PayrollEntry;
use Illuminate\Database\Eloquent\Model;

/** Part M: captures the immutable snapshot of one statutory output line. */
final class StatutorySnapshots
{
    /**
     * @param  array<string, mixed>|null  $rule  rule reference (rule_id, rule_version, rule_checksum)
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>  $calculated
     * @param  array<string, mixed>  $output
     */
    public function capture(StatutoryReturn $return, Model $entry, ?PayrollEntry $payroll, ?int $employeeId, ?array $rule, array $inputs, array $calculated, array $output, ?string $taxRegime = null): StatutorySnapshot
    {
        $supersedes = null;

        if ($return->parent_return_id && $employeeId) {
            $supersedes = StatutorySnapshot::query()->where('statutory_return_id', $return->parent_return_id)->where('employee_id', $employeeId)->latest('id')->value('id');
        }

        return StatutorySnapshot::query()->create([
            'statutory_return_id' => $return->getKey(),
            'entry_type' => $entry::class,
            'entry_id' => $entry->getKey(),
            'payroll_run_id' => $payroll?->payroll_run_id,
            'payroll_entry_id' => $payroll?->getKey(),
            'employee_id' => $employeeId,
            'establishment_id' => $return->establishment_id,
            'legal_entity_id' => $return->legal_entity_id,
            'tax_regime' => $taxRegime,
            'compliance_rule_id' => $rule['rule_id'] ?? null,
            'rule_version' => $rule['rule_version'] ?? null,
            'rule_checksum' => $rule['rule_checksum'] ?? null,
            'inputs' => $inputs,
            'calculated' => $calculated,
            'output' => $output,
            'supersedes_id' => $supersedes,
            'reason' => $supersedes ? ($return->reason ?? 'Revision') : null,
        ]);
    }
}
