<?php

namespace App\Domain\Compensation\Imports;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use Illuminate\Support\Carbon;

/**
 * Data import DI-4 (Payroll → Change pay group): move employees to another salary structure (pay group) from a date,
 * one employee per row. The CTC, currency, variable target and the fixed / input amounts of the pay items the new
 * structure also has are carried from the compensation in force on the date (read through CompensationOutput) unless
 * the row gives them. Each row becomes a line of a draft compensation cycle (CompensationRowImports): nobody's pay
 * changes until the cycle is submitted, reviewed, approved and executed by four different people. An employee already
 * on that structure on the date is skipped.
 */
final class PayGroupChangeImports extends CompensationRowImports
{
    public const TYPE = 'pay_group_changes';

    public const FIELDS = [
        'employee_code' => ['Employee code', true], 'structure_code' => ['New salary structure code', true], 'effective_from' => ['Effective from', true],
        'ctc_annual' => ['Annual CTC', false], 'change_type' => ['Change type', false], 'reason' => ['Reason', false],
    ];

    /** @return array<string, array{0: string, 1: bool}> the fixed fields plus a column per fixed-amount or input pay item (monthly) */
    public static function fields(): array
    {
        return self::FIELDS + collect(self::componentColumns())->mapWithKeys(fn ($name, $code) => ["component.{$code}" => ["{$name} ({$code}), monthly", false]])->all();
    }

    public static function intents(): bool
    {
        return false; // a pay group change always moves someone who has compensation on file
    }

    public function label(): string
    {
        return 'Change pay group upload';
    }

    protected function aliases(): array
    {
        return ['structure' => 'structure_code', 'new_structure' => 'structure_code', 'pay_group' => 'structure_code', 'new_pay_group' => 'structure_code',
            'effective_date' => 'effective_from', 'from' => 'effective_from', 'revision_month' => 'effective_from', 'ctc' => 'ctc_annual', 'annual_ctc' => 'ctc_annual', 'type' => 'change_type'] + $this->componentAliases();
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        $decision = parent::check($r, $context, $row, $import);
        if ($decision['action'] === 'update' || $decision['action'] === 'create') {
            $decision['action'] = 'create'; // the upload only adds lines; "new or existing" does not apply
        }

        return $decision;
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
        $ctc = isset($r['ctc_annual']) ? $this->money($r['ctc_annual']) : null;
        if (isset($r['ctc_annual']) && ($ctc === null || $ctc <= 0)) {
            $errors[] = 'Annual CTC must be a number above zero (blank keeps the CTC in force).';
        }
        $changeType = isset($r['change_type']) ? $this->changeType($r['change_type']) : 'revision';
        if ($changeType === null) {
            $errors[] = "Change type '{$r['change_type']}' is not one of: ".implode(', ', array_keys((array) config('peopleos.compensation.change_types'))).'.';
        }
        [$given, $valueErrors] = $this->componentValues($r);
        $errors = [...$errors, ...$valueErrors];
        if ($employee === null || $date === null || $structureId === null || $errors !== []) {
            return [$employee, [], $errors];
        }

        $current = app(CompensationOutput::class)->on($employee, $date);
        if ($current === null) {
            return [$employee, [], ["Employee {$employee->employee_code} has no compensation in force on {$date} to move; give a first CTC with Salary structure (employee CTC manual) or CTC upload."]];
        }
        if ($current->structureId === $structureId && ! $current->effectiveFrom->isSameDay(Carbon::parse($date))) {
            return [$employee, [], ["Employee {$employee->employee_code} is already on structure ".strtoupper((string) $r['structure_code'])." on {$date}."]];
        }
        // Carry the amounts of pay items the new structure also has (it refuses any other).
        $version = SalaryStructure::query()->find($structureId)?->versionOn($date);
        $codes = $version === null ? [] : $version->components()->with('component:id,code')->get()->map(fn ($i) => strtoupper((string) $i->component?->code))->filter()->all();
        $carried = array_intersect_key($current->componentValues, array_flip($codes));

        return [$employee, [
            'change_type' => $changeType, 'effective_from' => $date, 'salary_structure_id' => $structureId, 'ctc_annual' => $ctc ?? $current->ctcAnnual,
            'variable_target_annual' => $current->variableTargetAnnual, 'currency' => $current->currency, 'component_values' => array_merge($carried, $given),
            'reason' => $r['reason'] ?? 'Pay group change: '.$import->original_name,
        ], []];
    }

    public function format(): array
    {
        [$code] = $this->sampleCodes(1);
        $structure = $this->sampleStructure();
        $what = [
            'employee_code' => ["The employee's code.", $code],
            'structure_code' => ['Code of the active salary structure (pay group) to move to (Compensation → Salary structures).', $structure],
            'effective_from' => ['YYYY-MM-DD; after any finalized payroll of the employee.', now()->addMonthNoOverflow()->startOfMonth()->toDateString()],
            'ctc_annual' => ['Blank keeps the CTC in force on the date; commas are fine (6,00,000).', ''],
            'change_type' => ['One of: '.implode(', ', array_keys((array) config('peopleos.compensation.change_types', []))).'; blank = revision.', ''],
            'reason' => ['Shown on the change; blank uses the file name.', 'Moved to the sales pay group'],
        ];
        $columns = [];
        foreach (self::fields() as $field => [$label, $required]) {
            $columns[] = [$label, $required ? 'Yes' : 'No', $what[$field][0] ?? 'Monthly amount of this pay item; blank carries the amount in force when the new structure has the item.', $what[$field][1] ?? ''];
        }

        return ['columns' => $columns, 'notes' => [
            'Each row moves one employee who has compensation on file; an employee already on the structure is refused, the same change already in force is skipped.',
            'Running the upload prepares a draft compensation cycle per company. Nobody\'s pay changes until that cycle is submitted, reviewed, approved and executed by different people.',
            'Only you (the uploader) see, approve and run this file, unless another person checks compensation uploads.',
        ]];
    }

    protected function examples(): array
    {
        [$first, $second] = $this->sampleCodes(2);
        $structure = $this->sampleStructure();
        $date = now()->addMonthNoOverflow()->startOfMonth()->toDateString();
        $blank = array_fill(0, count(self::componentColumns()), '');

        return [
            [$first, $structure, $date, '', 'revision', 'Moved to the sales pay group', ...$blank],
            [$second, $structure, $date, '720000', 'revision', 'Moved with a new CTC', ...$blank],
        ];
    }

    private function sampleStructure(): string
    {
        return (string) (SalaryStructure::query()->where('status', 'active')->orderBy('id')->value('code') ?? 'STANDARD');
    }
}
