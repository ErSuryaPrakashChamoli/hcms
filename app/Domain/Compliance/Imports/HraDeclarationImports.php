<?php

namespace App\Domain\Compliance\Imports;

use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Compliance\Services\TaxSettings;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;

/**
 * Data import DI-4 (Payroll → HRA declaration): the rent an employee pays for the HRA exemption, one employee and year
 * per row — rent a month, months rented, whether the home is in a metro city, the landlord(s) and the address — saved
 * as the declaration's rent item through the guided declaration's service (the same landlord PAN and landlord count
 * rules as the screen). Which cities count as metro is the employee's answer, never worked out here.
 */
final class HraDeclarationImports extends DeclarationRowImports
{
    public const TYPE = 'hra_declarations';

    private const ITEM = 'HRA_RENT.rent';

    private const PAN = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'financial_year' => ['Financial year', true], 'monthly_rent' => ['Rent per month', true],
            'months' => ['Months rented', false], 'metro' => ['Metro city (yes/no)', true], 'city' => ['City', false], 'landlord_name' => ['Landlord name', false],
            'landlord_pan' => ['Landlord PAN', false], 'other_landlords' => ['Other landlords', false], 'address' => ['Address of the rented home', false], 'regime' => ['Tax regime', false],
        ];
    }

    public function label(): string
    {
        return 'HRA declaration upload';
    }

    protected function aliases(): array
    {
        return ['fy' => 'financial_year', 'year' => 'financial_year', 'rent' => 'monthly_rent', 'monthly_rent_amount' => 'monthly_rent', 'rent_months' => 'months',
            'metro' => 'metro', 'is_metro' => 'metro', 'landlord' => 'landlord_name', 'pan_of_landlord' => 'landlord_pan', 'address' => 'address'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        [$year, $yearError] = $this->yearOf($r);
        if ($yearError !== null) {
            $errors[] = $yearError;
        }
        $rent = $this->money($r['monthly_rent'] ?? null);
        if ($rent === null || $rent < 0) {
            $errors[] = 'Rent per month must be a number, zero or more (zero removes the rent).';
        }
        $months = $this->months($r);
        if ($months === null) {
            $errors[] = 'Months rented must be a whole number from 1 to 12 (blank = 12).';
        }
        if ($this->yesNo($r['metro'] ?? null) === null) {
            $errors[] = 'Metro city must be yes or no.';
        }
        $errors = [...$errors, ...$this->landlordErrors($r, (float) $rent)];
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
        if (($duplicate = $this->seenBefore("{$employeeId}|{$year}", $context, $row->row_number, 'employee and year')) !== null) {
            return ['action' => 'error', 'errors' => [$duplicate], 'employee_id' => $employeeId];
        }
        if (TdsEmployeeInvestment::query()->where('employee_id', $employeeId)->where('financial_year', $year)->where('item_key', self::ITEM)->where('proof_status', 'verified')->exists()) {
            return ['action' => 'skip', 'employee_id' => $employeeId, 'match' => ['reason' => 'Payroll verified the rent proof; an upload never changes it.']];
        }
        $declared = $current['items'][self::ITEM] ?? null;
        if ($declared === null) {
            return $rent > 0
                ? ['action' => 'create', 'employee_id' => $employeeId]
                : ['action' => 'skip', 'employee_id' => $employeeId, 'match' => ['reason' => 'No rent is declared to remove.']];
        }
        $same = $rent > 0 && $this->details($r, $rent, $months) == $this->comparable((array) ($declared['details'] ?? []))
            && round((float) $declared['amount'], 2) === round($rent * $months, 2) && $current['metro'] === $this->yesNo($r['metro']) && $this->regime($r, $current) === $current['regime'];

        return $same
            ? ['action' => 'skip', 'employee_id' => $employeeId, 'match' => ['reason' => 'Already declared with these details.']]
            : ['action' => 'update', 'employee_id' => $employeeId, 'match' => ['reason' => $rent > 0 ? 'Changes the rent declared.' : 'Removes the rent.']];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = Employee::query()->findOrFail($row->employee_id);
        [$year] = $this->yearOf($r);
        $rent = (float) $this->money($r['monthly_rent']);
        $months = (int) $this->months($r);
        $input = $this->declarations()->currentInput($employee, $year);
        $input['regime'] = $this->regime($r, $input) ?? $input['regime'];
        $input['metro'] = (bool) $this->yesNo($r['metro']);
        $input['items'][self::ITEM] = ['amount' => round($rent * $months, 2), 'details' => $this->details($r, $rent, $months)];
        $declaration = $this->save($user, $employee, $year, $input);

        return ($rent > 0 ? 'Rent of '.number_format($rent * $months, 2).' declared' : 'Rent removed')." ({$declaration->financial_year}, {$declaration->status})";
    }

    private function months(array $r): ?int
    {
        if (! isset($r['months'])) {
            return 12;
        }

        return ctype_digit($r['months']) && (int) $r['months'] >= 1 && (int) $r['months'] <= 12 ? (int) $r['months'] : null;
    }

    /** The rent item's details as the declaration page stores them (plus the city). @return array<string, mixed> */
    private function details(array $r, float $rent, int $months): array
    {
        return array_filter([
            'monthly_rent' => $rent, 'months' => $months, 'landlord_name' => isset($r['landlord_name']) ? trim($r['landlord_name']) : null,
            'landlord_pan' => isset($r['landlord_pan']) ? strtoupper(trim($r['landlord_pan'])) : null, 'address' => $r['address'] ?? null, 'city' => $r['city'] ?? null,
            'landlords' => $this->otherLandlords($r['other_landlords'] ?? null) ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** @param  array<string, mixed>  $details */
    private function comparable(array $details): array
    {
        $details['monthly_rent'] = (float) ($details['monthly_rent'] ?? 0);
        $details['months'] = (int) ($details['months'] ?? 0);

        return $details;
    }

    /** "Ravi Kumar ABCDE1234F; Sita Devi" → [[name, pan], [name, null]]. @return list<array{name: string, pan: ?string}> */
    private function otherLandlords(?string $value): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(';', (string) $value))) as $entry) {
            $parts = preg_split('/\s+/', $entry);
            $last = strtoupper((string) end($parts));
            $pan = preg_match(self::PAN, $last) ? $last : null;
            $name = trim($pan !== null ? implode(' ', array_slice($parts, 0, -1)) : $entry);
            if ($name !== '') {
                $out[] = ['name' => $name, 'pan' => $pan];
            }
        }

        return $out;
    }

    /** The screen's landlord rules: a PAN that looks like one, every landlord's PAN when asked for, at most N landlords. @return list<string> */
    private function landlordErrors(array $r, float $rent): array
    {
        if ($rent <= 0) {
            return [];
        }
        $errors = [];
        $settings = app(TaxSettings::class);
        if (isset($r['landlord_pan']) && ! preg_match(self::PAN, strtoupper(trim($r['landlord_pan'])))) {
            $errors[] = 'Landlord PAN must look like ABCDE1234F.';
        }
        $others = $this->otherLandlords($r['other_landlords'] ?? null);
        if (count($others) + 1 > $settings->maxLandlords()) {
            $errors[] = 'Rent can be declared for at most '.$settings->maxLandlords().' landlord(s).';
        }
        if ($settings->landlordPanRequired() && (! isset($r['landlord_pan']) || collect($others)->contains(fn ($l) => $l['pan'] === null))) {
            $errors[] = 'Give the PAN of every landlord: your organisation asks for it.';
        }

        return $errors;
    }

    public function format(): array
    {
        [$code] = $this->sampleCodes(1);

        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", $code],
                ['Financial year', 'Yes', 'April to March, as 2026-27: last, this or next year.', $this->thisYear()],
                ['Rent per month', 'Yes', 'Rent paid a month; 0 removes the rent.', '25000'],
                ['Months rented', 'No', 'Months rented in the year, 1 to 12; blank = 12.', '12'],
                ['Metro city (yes/no)', 'Yes', 'Whether the home is in a metro city for the HRA exemption (the employee\'s answer, as on the declaration).', 'yes'],
                ['City', 'No', 'The city of the rented home, for the record.', 'Mumbai'],
                ['Landlord name', 'No', 'The landlord.', 'Ramesh Iyer'],
                ['Landlord PAN', 'No', 'ABCDE1234F; needed when your organisation asks for every landlord\'s PAN.', 'AAAPI1234C'],
                ['Other landlords', 'No', 'More owners of the same home: "name PAN" separated by ";".', ''],
                ['Address of the rented home', 'No', 'The address.', '12 Hill Road, Bandra West'],
                ['Tax regime', 'No', 'old or new; blank keeps the declaration\'s regime. The HRA exemption counts only in the old regime.', 'old'],
            ],
            'notes' => [
                'One row per employee and year; the row replaces the rent declared (choose Update existing records or Insert and update). The same details are skipped.',
                'The declaration screen\'s rules apply: landlord PAN and landlord count settings, payroll in your scope; a verified rent proof or a verified declaration is never changed.',
                'A declaration started by the upload is submitted to payroll; an existing one keeps its status.',
            ],
        ];
    }

    protected function examples(): array
    {
        [$first, $second] = $this->sampleCodes(2);

        return [
            [$first, $this->thisYear(), '25000', '12', 'yes', 'Mumbai', 'Ramesh Iyer', 'AAAPI1234C', '', '12 Hill Road, Bandra West', 'old'],
            [$second, $this->thisYear(), '14000', '9', 'no', 'Pune', 'Sunita Kulkarni', 'BBBPK5678D', '', '4 Lake View, Kothrud', 'old'],
        ];
    }
}
