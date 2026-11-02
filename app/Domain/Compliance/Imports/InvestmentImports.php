<?php

namespace App\Domain\Compliance\Imports;

use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Compliance\Support\InvestmentCatalogue;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;

/**
 * Data import DI-4 (Payroll → Investments): tax-saving declarations item by item (80C, 80CCD(1B), 80D, 80E, 80G, 80TTA,
 * home-loan interest 24(b), other income), one employee, year and catalogue item per row, saved through the guided
 * declaration's service. An item already declared is updated (amount 0 removes it); an item whose proof payroll verified
 * is never changed. Rent (HRA) has its own upload.
 */
final class InvestmentImports extends DeclarationRowImports
{
    public const TYPE = 'investments';

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'financial_year' => ['Financial year', true], 'item' => ['Investment item', true],
            'amount' => ['Amount declared', true], 'description' => ['Description', false], 'regime' => ['Tax regime', false],
        ];
    }

    public function label(): string
    {
        return 'Investment declaration upload';
    }

    protected function aliases(): array
    {
        return ['fy' => 'financial_year', 'year' => 'financial_year', 'item_key' => 'item', 'investment' => 'item', 'declared_amount' => 'amount', 'remarks' => 'description'];
    }

    /** @return array<string, string> item key => "section: label", every catalogue item but rent */
    public static function items(): array
    {
        $out = [];
        foreach (InvestmentCatalogue::SECTIONS as $section => $meta) {
            if ($section === 'HRA_RENT') {
                continue;
            }
            foreach ($meta['items'] as $key => $item) {
                $out["{$section}.{$key}"] = "{$meta['code']}: {$item['label']}";
            }
        }

        return $out;
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        [$year, $yearError] = $this->yearOf($r);
        if ($yearError !== null) {
            $errors[] = $yearError;
        }
        $item = $this->item($r['item'] ?? null);
        if ($item === null) {
            $errors[] = "Investment item '".($r['item'] ?? '')."' is not in the list (use a key such as 80C.ppf; rent goes on the HRA declaration upload).";
        }
        $amount = $this->money($r['amount'] ?? null);
        if ($amount === null || $amount < 0) {
            $errors[] = 'Amount declared must be a number, zero or more (zero removes the item).';
        }
        if ($item !== null && InvestmentCatalogue::isOther($item) && $amount > 0 && blank($r['description'] ?? null)) {
            $errors[] = 'Describe the investment for a "something else" item.';
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }
        $employee = Employee::query()->findOrFail($employeeId);
        if (($refusal = $this->refusal($employee, $year, $import)) !== null) {
            return ['action' => 'error', 'errors' => [$refusal], 'employee_id' => $employeeId];
        }
        $current = $this->declarations()->currentInput($employee, $year);
        if ($this->regime($r, $current) === null) {
            return ['action' => 'error', 'errors' => ['Tax regime must be old or new (or blank to keep it).'], 'employee_id' => $employeeId];
        }
        if (($duplicate = $this->seenBefore("{$employeeId}|{$year}|{$item}", $context, $row->row_number, 'employee, year and item')) !== null) {
            return ['action' => 'error', 'errors' => [$duplicate], 'employee_id' => $employeeId];
        }

        $verified = TdsEmployeeInvestment::query()->where('employee_id', $employeeId)->where('financial_year', $year)->where('item_key', $item)->where('proof_status', 'verified')->exists();
        if ($verified) {
            return ['action' => 'skip', 'employee_id' => $employeeId, 'match' => ['reason' => 'Payroll verified the proof for this item; an upload never changes it.']];
        }
        $declared = $current['items'][$item] ?? null;
        if ($declared === null) {
            return $amount > 0
                ? ['action' => 'create', 'employee_id' => $employeeId]
                : ['action' => 'skip', 'employee_id' => $employeeId, 'match' => ['reason' => 'Nothing is declared for this item to remove.']];
        }
        $sameDescription = ! InvestmentCatalogue::isOther($item) || ! isset($r['description']) || trim($r['description']) === (string) $declared['description'];

        return round((float) $declared['amount'], 2) === $amount && $sameDescription && $this->regime($r, $current) === $current['regime']
            ? ['action' => 'skip', 'employee_id' => $employeeId, 'match' => ['reason' => 'Already declared with this amount.']]
            : ['action' => 'update', 'employee_id' => $employeeId, 'match' => ['reason' => $amount > 0 ? 'Changes the amount declared.' : 'Removes the item.']];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = Employee::query()->findOrFail($row->employee_id);
        [$year] = $this->yearOf($r);
        $item = $this->item($r['item']);
        $amount = (float) $this->money($r['amount']);
        $input = $this->declarations()->currentInput($employee, $year);
        $input['regime'] = $this->regime($r, $input) ?? $input['regime'];
        $input['items'][$item] = ['amount' => $amount, 'description' => $r['description'] ?? ($input['items'][$item]['description'] ?? null), 'details' => $input['items'][$item]['details'] ?? null];
        $declaration = $this->save($user, $employee, $year, $input);

        return ($amount > 0 ? self::items()[$item].' declared: '.number_format($amount, 2) : self::items()[$item].' removed')." ({$declaration->financial_year}, {$declaration->status})";
    }

    /** The catalogue item a cell names (its key, case-insensitive, or its label), never rent. */
    private function item(?string $value): ?string
    {
        $given = mb_strtolower(trim((string) $value));
        foreach (self::items() as $key => $label) {
            [, $itemLabel] = explode(': ', $label, 2);
            if ($given === mb_strtolower($key) || $given === mb_strtolower($itemLabel)) {
                return $key;
            }
        }

        return null;
    }

    public function format(): array
    {
        [$code] = $this->sampleCodes(1);

        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", $code],
                ['Financial year', 'Yes', 'April to March, as 2026-27: last, this or next year.', $this->thisYear()],
                ['Investment item', 'Yes', 'An item key from the list below (or its name).', '80C.ppf'],
                ['Amount declared', 'Yes', 'The amount for the year; 0 removes the item.', '150000'],
                ['Description', 'No', 'Needed for a "something else" item (….other).', ''],
                ['Tax regime', 'No', 'old or new; blank keeps the declaration\'s regime (new for a declaration started by the upload). Investments count only in the old regime.', 'old'],
            ],
            'codes' => self::items(),
            'codes_title' => 'Investment items',
            'notes' => [
                'One row per employee, year and item. An item already declared is updated (choose Update existing records or Insert and update); the same amount is skipped.',
                'The declaration screen\'s rules apply: payroll in your scope, payroll may edit submitted declarations, items with a verified proof and verified declarations are never changed.',
                'A declaration started by the upload is submitted to payroll; an existing one keeps its status. Section limits come from the TDS rule, as on the screen.',
            ],
        ];
    }

    protected function examples(): array
    {
        [$first, $second] = $this->sampleCodes(2);

        return [
            [$first, $this->thisYear(), '80C.ppf', '150000', '', 'old'],
            [$second, $this->thisYear(), '80D.self_family', '25000', '', 'old'],
        ];
    }
}
