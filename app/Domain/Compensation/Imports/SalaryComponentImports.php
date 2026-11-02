<?php

namespace App\Domain\Compensation\Imports;

use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Employment\Imports\EmployeeImport;

/**
 * Data import DI-4 (Payroll → Salary structure (employee CTC manual)): an employee's CTC with the monthly amount of each
 * fixed and input pay item given, one employee per row (a structure that works out its CTC from the amounts needs no
 * CTC). Each row becomes a line of a draft compensation cycle (CompensationRowImports): a first CTC for an employee
 * with none on file (new), a revision otherwise (existing). Nobody's pay changes until the cycle is submitted,
 * reviewed, approved and executed by four different people.
 */
final class SalaryComponentImports extends CompensationRowImports
{
    public const TYPE = 'salary_components';

    public const FIELDS = [
        'employee_code' => ['Employee code', true], 'structure_code' => ['Salary structure code', true], 'effective_from' => ['Effective from', true],
        'ctc_annual' => ['Annual CTC', false], 'currency' => ['Currency', false], 'variable_target_annual' => ['Variable target (annual)', false],
        'change_type' => ['Change type', false], 'reason' => ['Reason', false],
    ];

    /** @return array<string, array{0: string, 1: bool}> the fixed fields plus a column per fixed-amount or input pay item (monthly) */
    public static function fields(): array
    {
        return self::FIELDS + collect(self::componentColumns())->mapWithKeys(fn ($name, $code) => ["component.{$code}" => ["{$name} ({$code}), monthly", false]])->all();
    }

    public function label(): string
    {
        return 'Salary structure (component amounts) upload';
    }

    protected function aliases(): array
    {
        return ['structure' => 'structure_code', 'salary_structure' => 'structure_code', 'pay_group' => 'structure_code', 'effective_date' => 'effective_from', 'from' => 'effective_from',
            'ctc' => 'ctc_annual', 'annual_ctc' => 'ctc_annual', 'variable' => 'variable_target_annual', 'variable_target' => 'variable_target_annual', 'type' => 'change_type'] + $this->componentAliases();
    }

    protected function proposal(array $r, array $context, EmployeeImport $import): array
    {
        [$employee, $errors] = $this->employee($r, $context);
        $structureId = $this->structureId($r['structure_code'] ?? null, $context);
        if (isset($r['structure_code']) && $structureId === null) {
            $errors[] = "Salary structure '{$r['structure_code']}' does not exist.";
        }
        $date = $this->date($r['effective_from'] ?? null);
        if ($date === null) {
            $errors[] = 'Effective from must be a date (YYYY-MM-DD).';
        }
        $amounts = [];
        foreach (['ctc_annual' => 'Annual CTC', 'variable_target_annual' => 'Variable target (annual)'] as $field => $label) {
            if (isset($r[$field])) {
                $amounts[$field] = $this->money($r[$field]);
                if ($amounts[$field] === null) {
                    $errors[] = "{$label} '{$r[$field]}' is not a number.";
                }
            }
        }
        [$components, $valueErrors] = $this->componentValues($r);
        $errors = [...$errors, ...$valueErrors];
        if ($components === [] && $valueErrors === []) {
            $errors[] = 'Give the monthly amount of at least one pay item (for a CTC alone use CTC (employee CTC auto)).';
        }
        $changeType = null;
        if (isset($r['change_type'])) {
            $changeType = $this->changeType($r['change_type']);
            if ($changeType === null) {
                $errors[] = "Change type '{$r['change_type']}' is not one of: ".implode(', ', array_keys((array) config('peopleos.compensation.change_types'))).'.';
            }
        } elseif ($employee !== null && $date !== null) {
            $changeType = app(UploadTimeline::class)->hasEarlier($employee, $date) ? 'revision' : 'hire';
        }

        return [$employee, [
            'change_type' => $changeType, 'effective_from' => $date, 'salary_structure_id' => $structureId,
            'ctc_annual' => $amounts['ctc_annual'] ?? null, 'variable_target_annual' => $amounts['variable_target_annual'] ?? null,
            'currency' => $r['currency'] ?? null, 'component_values' => $components, 'reason' => $r['reason'] ?? 'Salary structure upload: '.$import->original_name,
        ], $errors];
    }

    public function format(): array
    {
        [$code] = $this->sampleCodes(1);
        $what = [
            'employee_code' => ["The employee's code.", $code],
            'structure_code' => ['Code of an active salary structure (Compensation → Salary structures).', $this->sampleStructure()],
            'effective_from' => ['YYYY-MM-DD; after any finalized payroll of the employee.', now()->addMonthNoOverflow()->startOfMonth()->toDateString()],
            'ctc_annual' => ['Annual cost to company; commas are fine (6,00,000). Not needed for a structure that works out its CTC from the amounts.', '600000'],
            'currency' => ['Three-letter code; blank means the organisation\'s base currency.', ''],
            'variable_target_annual' => ['Annual variable pay at target, if any.', ''],
            'change_type' => ['One of: '.implode(', ', array_keys((array) config('peopleos.compensation.change_types', []))).'. Blank: hire for a first CTC, revision otherwise; correction replaces a CTC starting on the same date.', ''],
            'reason' => ['Shown on the change; blank uses the file name.', 'Go-live salaries'],
        ];
        $columns = [];
        foreach (self::fields() as $field => [$label, $required]) {
            $columns[] = [$label, $required ? 'Yes' : 'No', $what[$field][0] ?? 'Monthly amount of this pay item (it must be part of the structure); blank uses the structure\'s default.', $what[$field][1] ?? ''];
        }

        return ['columns' => $columns, 'notes' => [
            'One row per employee with the amount of each fixed or input pay item; at least one amount is needed. Employees with no CTC on file get a first one (new); the rest are revised (existing).',
            'Running the upload prepares a draft compensation cycle per company. Nobody\'s pay changes until that cycle is submitted, reviewed, approved and executed by different people.',
            'Only you (the uploader) see, approve and run this file, unless another person checks compensation uploads.',
        ]];
    }

    protected function examples(): array
    {
        [$first, $second] = $this->sampleCodes(2);
        $structure = $this->sampleStructure();
        $date = now()->addMonthNoOverflow()->startOfMonth()->toDateString();
        $version = SalaryStructure::query()->where('code', $structure)->first()?->versionOn($date);
        $inStructure = $version === null ? [] : $version->components()->with('component:id,code')->get()->map(fn ($i) => strtoupper((string) $i->component?->code))->filter()->all();
        $codes = array_map('strval', array_keys(self::componentColumns()));
        $amount = fn (int $base) => array_map(fn (string $c) => in_array(strtoupper($c), $inStructure, true) || ($inStructure === [] && $c === ($codes[0] ?? null)) ? (string) $base : '', $codes);

        return [
            [$first, $structure, $date, '600000', '', '', '', 'Go-live salaries', ...$amount(1600)],
            [$second, $structure, $date, '840000', '', '', '', 'Go-live salaries', ...$amount(2400)],
        ];
    }

    private function sampleStructure(): string
    {
        return (string) (SalaryStructure::query()->where('status', 'active')->orderBy('id')->value('code') ?? 'STANDARD');
    }
}
