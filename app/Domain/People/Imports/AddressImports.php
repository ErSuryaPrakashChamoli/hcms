<?php

namespace App\Domain\People\Imports;

use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Imports\Concerns\WorkforceRows;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\ProfileChangeGuard;
use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\ChangeAddressAction;
use App\Domain\People\Models\PersonAddress;
use App\Support\Geography\Countries;
use Illuminate\Support\Facades\Validator;

/**
 * Data import (Workforce → Addresses; owner, 2026-11-27: every detail on the Employee 360 Profile can be uploaded): one row
 * per address of an employee — present (current), permanent or correspondence — written through ChangeAddressAction
 * (organisation scope, audit, timeline, a document "required with" the address first), as the Addresses tab writes it.
 * A row finds the address of that kind in effect today and updates it; otherwise it adds one. Blank cells keep what is
 * on file; nothing is removed. A state is taken by name or code; for India it becomes the code the statutory rules use.
 */
final class AddressImports extends RowImports
{
    use WorkforceRows;

    public const TYPE = 'addresses';

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'type' => ['Address type', true], 'address_line_1' => ['Address line 1', true],
            'address_line_2' => ['Address line 2', false], 'city' => ['City', false], 'state' => ['State', false], 'postal_code' => ['PIN / postal code', false],
            'country' => ['Country', false], 'effective_from' => ['Living here since', false],
        ];
    }

    public function label(): string
    {
        return 'Addresses upload';
    }

    protected function module(): string
    {
        return 'people';
    }

    protected function entityType(): string
    {
        return PersonAddress::class;
    }

    protected function aliases(): array
    {
        return ['address_type' => 'type', 'kind' => 'type', 'address' => 'address_line_1', 'address_1' => 'address_line_1', 'line_1' => 'address_line_1',
            'address_line1' => 'address_line_1', 'house' => 'address_line_1', 'street' => 'address_line_2', 'address_2' => 'address_line_2', 'line_2' => 'address_line_2',
            'address_line2' => 'address_line_2', 'locality' => 'address_line_2', 'area' => 'address_line_2', 'town' => 'city', 'district' => 'city',
            'state_code' => 'state', 'province' => 'state', 'pin' => 'postal_code', 'pincode' => 'postal_code', 'pin_code' => 'postal_code',
            'zip' => 'postal_code', 'zip_code' => 'postal_code', 'postcode' => 'postal_code', 'country_code' => 'country', 'from' => 'effective_from',
            'since' => 'effective_from', 'effective_date' => 'effective_from'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $employee = $employeeId === null ? null : Employee::query()->with('person')->find($employeeId);
        [$data, $problems] = $this->parse($r);
        $errors = [...$errors, ...$problems];
        if ($errors !== [] || $employee === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        $key = $employeeId.'|'.$data['type'];
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ["The same employee's ".mb_strtolower(self::typeLabel($data['type']))." address is in row {$context['seen'][$key]}."], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;

        $address = $this->onFile($employee, $data['type']);
        if (($refusal = $this->refusal($import, $employee, $address === null ? 'employee.create' : 'employee.update')) !== null) {
            return ['action' => 'error', 'errors' => [$refusal], 'employee_id' => $employeeId];
        }
        $match = ['employee_id' => $employeeId, 'employee_code' => $employee->employee_code, 'name' => $employee->person?->full_name,
            'matched_on' => $address === null ? ['employee code'] : ['employee code', 'address type']];
        $changed = $address === null ? array_keys($data) : array_keys(array_filter($data, fn ($value, $field) => ! self::sameValue($address->getAttribute($field), $value), ARRAY_FILTER_USE_BOTH));
        if ($address !== null && $changed === []) {
            return ['action' => 'skip', 'match' => $match + ['reason' => 'Already on file as given.'], 'employee_id' => $employeeId];
        }
        try {
            app(ProfileChangeGuard::class)->requireLinkedDocument($employee, 'address');
        } catch (ProfileChangeRefused $e) {
            return ['action' => 'error', 'errors' => [$e->getMessage()], 'employee_id' => $employeeId];
        }

        return $address === null
            ? ['action' => 'create', 'match' => $match, 'employee_id' => $employeeId]
            : ['action' => 'update', 'match' => $match + ['changes' => $changed], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = $this->employeeForRun($row->employee_id);
        [$data, $problems] = $this->parse($r);
        if ($problems !== []) {
            throw new ProfileChangeRefused(implode(' ', $problems));
        }
        $address = $this->onFile($employee, $data['type']);
        $action = app(ChangeAddressAction::class);
        if ($address === null) {
            $action->add($employee, $data + ['country_code' => 'IN'], $user, $this->why($import), $this->origin($import));

            return 'Added the '.mb_strtolower(self::typeLabel($data['type'])).' address of '.$employee->employee_code;
        }
        $onFile = $address->only(['type', 'country_code', 'address_line_1', 'address_line_2', 'city', 'state_code', 'postal_code']);
        $onFile['effective_from'] = $address->effective_from?->toDateString();
        $action->update($employee, $address, array_merge(array_filter($onFile, fn ($v) => $v !== null), $data), $user, $this->why($import), $this->origin($import));

        return 'Updated the '.mb_strtolower(self::typeLabel($data['type'])).' address of '.$employee->employee_code;
    }

    public function format(): array
    {
        $types = (array) config('peopleos.people.address_types', []);

        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Address type', 'Yes', 'One of: '.implode(', ', $types).' (present is read as current, communication as correspondence).', 'Current'],
                ['Address line 1', 'Yes', 'House, building and street.', '12, MG Road'],
                ['Address line 2', 'No', 'Area or locality.', 'Indiranagar'],
                ['City', 'No', 'Town or city.', 'Bengaluru'],
                ['State', 'No', 'The state by name or code (Karnataka or KA). For India it is kept as the code payroll and compliance use.', 'Karnataka'],
                ['PIN / postal code', 'No', 'Up to 16 characters; an Indian PIN code has 6 digits.', '560038'],
                ['Country', 'No', 'By name or two-letter code. Blank: India for a new address, or the country on file.', 'India'],
                ['Living here since', 'No', 'YYYY-MM-DD, DD-MM-YYYY or DD/MM/YYYY.', ''],
            ],
            'notes' => [
                'A row finds the address of that type in effect today and updates it; any other row adds the address. One row per employee and address type.',
                'Blank cells keep what is on file. Nothing is removed by an upload: remove an address on the Employee 360 (Addresses).',
                'If your organisation requires an address proof with an address, upload the document first (Employee documents upload).',
            ],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';

        return [
            [$code, 'Current', '12, MG Road', 'Indiranagar', 'Bengaluru', 'Karnataka', '560038', 'India', ''],
            [$code, 'Permanent', '4, Station Road', 'Civil Lines', 'Jaipur', 'Rajasthan', '302006', 'India', ''],
        ];
    }

    /**
     * The row as ChangeAddressAction takes it (type always; the rest only when given) and what is wrong.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function parse(array $r): array
    {
        $errors = [];
        $type = self::typeCode($r['type'] ?? null);
        if ($type === null) {
            $errors[] = isset($r['type']) ? "Address type '{$r['type']}' is not one of: ".implode(', ', (array) config('peopleos.people.address_types', [])).'.' : 'Address type is required.';
        }
        $data = ['type' => (string) $type];
        foreach (['address_line_1', 'address_line_2', 'city', 'postal_code'] as $field) {
            if (isset($r[$field])) {
                $data[$field] = trim((string) preg_replace('/\s+/u', ' ', $r[$field]));
            }
        }
        if (isset($r['country'])) {
            $country = Countries::codeOf($r['country']);
            if ($country === null) {
                $errors[] = "Country '{$r['country']}' is not a country name or two-letter code.";
            } else {
                $data['country_code'] = $country;
            }
        }
        if (isset($r['state'])) {
            $data['state_code'] = self::stateCode($r['state'], $data['country_code'] ?? 'IN');
            if (mb_strlen($data['state_code']) > 16) {
                $errors[] = "State '{$r['state']}' is longer than 16 characters: use its code.";
            }
        }
        if (($from = $this->dateCell($r['effective_from'] ?? null, 'Living here since', $errors)) !== null) {
            $data['effective_from'] = $from;
        }
        if ($errors === []) {
            $rules = array_intersect_key(app(ChangeAddressAction::class)->rules(), $data);
            $validator = Validator::make($data, $rules, [], ['address_line_1' => 'address line 1', 'address_line_2' => 'address line 2', 'postal_code' => 'PIN / postal code', 'effective_from' => 'living here since']);
            array_push($errors, ...$validator->errors()->all());
        }

        return [$data, array_values(array_unique($errors))];
    }

    /** The address of that type in effect today. */
    private function onFile(Employee $employee, string $type): ?PersonAddress
    {
        return PersonAddress::query()->where('person_id', $employee->person_id)->where('type', $type)->currentlyEffective()->orderByDesc('id')->first();
    }

    private static function typeCode(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = mb_strtolower(trim(str_replace(' address', '', mb_strtolower($value))));
        $v = ['present' => 'current', 'residential' => 'current', 'communication' => 'correspondence', 'mailing' => 'correspondence', 'native' => 'permanent'][$v] ?? $v;
        foreach ((array) config('peopleos.people.address_types', []) as $code => $label) {
            if ($v === mb_strtolower((string) $code) || $v === mb_strtolower((string) $label)) {
                return (string) $code;
            }
        }

        return null;
    }

    private static function typeLabel(string $type): string
    {
        return (string) (config('peopleos.people.address_types')[$type] ?? ucfirst($type));
    }

    /** An Indian state by name or code as the statutory code (Karnataka → KA); any other state as given. */
    private static function stateCode(string $value, string $country): string
    {
        $value = trim($value);
        if (mb_strtoupper($country) !== 'IN') {
            return $value;
        }
        foreach ((array) config('peopleos.compliance.states', []) as $code => $name) {
            if (mb_strtoupper($value) === mb_strtoupper((string) $code) || mb_strtolower($value) === mb_strtolower((string) $name)) {
                return (string) $code;
            }
        }

        return ['TS' => 'TG', 'CT' => 'CG', 'OR' => 'OD', 'UT' => 'UK', 'UTTARANCHAL' => 'UK', 'ORISSA' => 'OD'][mb_strtoupper($value)] ?? $value;
    }
}
