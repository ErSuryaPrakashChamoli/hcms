<?php

namespace App\Domain\Compensation\Support;

use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use Illuminate\Support\Carbon;

/** One approved, effective-dated compensation of an employee, as other modules see it (read-only). */
final class CompensationSnapshot
{
    /** @param  array<string, float>  $componentValues  monthly fixed amounts by component code */
    public function __construct(
        public readonly int $assignmentId,
        public readonly int $employeeId,
        public readonly ?int $changeId,
        public readonly string $changeType,
        public readonly int $structureId,
        public readonly ?int $structureVersionId,
        public readonly ?string $structureCode,
        public readonly string $currency,
        public readonly string $payFrequency,
        public readonly float $ctcAnnual,
        public readonly ?float $variableTargetAnnual,
        public readonly array $componentValues,
        public readonly Carbon $effectiveFrom,
        public readonly ?Carbon $effectiveTo,
        public readonly ?Carbon $approvedAt,
        /** Sensitive (field security): consumers show it only to readers already allowed compensation. */
        public readonly ?string $reason = null,
    ) {}

    public static function fromRow(EmployeeSalaryAssignment $row, ?string $structureCode = null): self
    {
        return new self(
            assignmentId: (int) $row->id,
            employeeId: (int) $row->employee_id,
            changeId: $row->compensation_change_id ? (int) $row->compensation_change_id : null,
            changeType: (string) $row->change_type,
            structureId: (int) $row->salary_structure_id,
            structureVersionId: isset($row->getAttributes()['salary_structure_version_id']) ? (int) $row->getAttributes()['salary_structure_version_id'] : null,
            structureCode: $structureCode,
            currency: (string) $row->currency,
            payFrequency: (string) ($row->pay_frequency ?? 'monthly'),
            ctcAnnual: (float) $row->ctc_annual,
            variableTargetAnnual: $row->variable_target_annual !== null ? (float) $row->variable_target_annual : null,
            componentValues: array_map('floatval', (array) ($row->component_values ?? [])),
            effectiveFrom: $row->effective_from->copy()->startOfDay(),
            effectiveTo: $row->effective_to?->copy()->startOfDay(),
            approvedAt: $row->approved_at,
            reason: $row->reason,
        );
    }

    public function monthlyCtc(): float
    {
        return round($this->ctcAnnual / 12, 2);
    }

    /** Monthly fixed value for a component code (0 when the assignment does not set it). */
    public function valueFor(string $code): float
    {
        return (float) ($this->componentValues[strtoupper($code)] ?? 0);
    }

    public function hasValueFor(string $code): bool
    {
        return array_key_exists(strtoupper($code), $this->componentValues);
    }

    public function isInForceOn(Carbon|string $date): bool
    {
        $day = Carbon::parse($date)->startOfDay();

        return $this->effectiveFrom->lte($day) && ($this->effectiveTo === null || $this->effectiveTo->gte($day));
    }
}
