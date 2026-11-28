<?php

namespace App\Domain\People\Imports;

use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Imports\Concerns\WorkforceRows;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\ChangeEmergencyContactAction;
use App\Domain\People\Models\PersonEmergencyContact;
use Illuminate\Support\Facades\Validator;

/**
 * Data import (Workforce → Emergency contacts; owner, 2026-11-27: every detail on the Employee 360 Profile can be
 * uploaded): one row per emergency contact of an employee, written through ChangeEmergencyContactAction (organisation
 * scope, audit, timeline; the phone checked and stored as +<code>… when a country code is given), as the Emergency
 * contacts tab writes it. A row finds the contact on file with the same name (not case-sensitive) and updates it;
 * otherwise it adds one. Blank cells keep what is on file; nothing is removed.
 */
final class EmergencyContactImports extends RowImports
{
    use WorkforceRows;

    public const TYPE = 'emergency_contacts';

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'name' => ['Contact name', true], 'relation' => ['Relation', false],
            'phone_country_code' => ['Phone country code', false], 'phone' => ['Phone', false], 'alternate_phone' => ['Alternate phone', false],
            'email' => ['E-mail', false], 'priority' => ['Priority', false],
        ];
    }

    public function label(): string
    {
        return 'Emergency contacts upload';
    }

    protected function module(): string
    {
        return 'people';
    }

    protected function entityType(): string
    {
        return PersonEmergencyContact::class;
    }

    protected function aliases(): array
    {
        return ['contact_name' => 'name', 'contact_person' => 'name', 'emergency_contact' => 'name', 'emergency_contact_name' => 'name', 'relationship' => 'relation',
            'contact_relation' => 'relation', 'country_code' => 'phone_country_code', 'dialling_code' => 'phone_country_code', 'mobile' => 'phone',
            'mobile_number' => 'phone', 'phone_number' => 'phone', 'contact_number' => 'phone', 'emergency_phone' => 'phone', 'emergency_contact_number' => 'phone',
            'alternate_number' => 'alternate_phone', 'other_phone' => 'alternate_phone', 'landline' => 'alternate_phone', 'email_address' => 'email',
            'order' => 'priority', 'sequence' => 'priority'];
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
        $key = $employeeId.'|'.mb_strtolower($data['name']);
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ["The same contact is in row {$context['seen'][$key]}."], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;

        $contact = $this->onFile($employee, $data['name']);
        if ($contact === null && blank($data['phone'] ?? null)) {
            return ['action' => 'error', 'errors' => ['Phone is required to add a contact.'], 'employee_id' => $employeeId];
        }
        if (($refusal = $this->refusal($import, $employee, $contact === null ? 'employee.create' : 'employee.update')) !== null) {
            return ['action' => 'error', 'errors' => [$refusal], 'employee_id' => $employeeId];
        }
        $match = ['employee_id' => $employeeId, 'employee_code' => $employee->employee_code, 'name' => $employee->person?->full_name,
            'matched_on' => $contact === null ? ['employee code'] : ['employee code', 'contact name']];
        if ($contact === null) {
            return ['action' => 'create', 'match' => $match, 'employee_id' => $employeeId];
        }
        $changed = array_keys(array_filter($data, fn ($value, $field) => $field !== 'name' && ! self::sameValue($contact->getAttribute($field), $value), ARRAY_FILTER_USE_BOTH));

        return $changed === []
            ? ['action' => 'skip', 'match' => $match + ['reason' => 'Already on file as given.'], 'employee_id' => $employeeId]
            : ['action' => 'update', 'match' => $match + ['changes' => $changed], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = $this->employeeForRun($row->employee_id);
        [$data, $problems] = $this->parse($r);
        if ($problems !== []) {
            throw new ProfileChangeRefused(implode(' ', $problems));
        }
        $contact = $this->onFile($employee, $data['name']);
        $action = app(ChangeEmergencyContactAction::class);
        if ($contact === null) {
            $action->add($employee, $data + ['priority' => $this->nextPriority($employee)], $user, $this->why($import), $this->origin($import));

            return "Added emergency contact {$data['name']} for {$employee->employee_code}";
        }
        $data['name'] = $contact->name; // the name is the key: a match not by case keeps the name on file
        $onFile = array_filter($contact->only(['name', 'relation', 'phone_country_code', 'phone', 'alternate_phone', 'email', 'priority']), fn ($v) => $v !== null);
        $action->update($employee, $contact, array_merge($onFile, $data), $user, $this->why($import), $this->origin($import));

        return "Updated emergency contact {$contact->name} for {$employee->employee_code}";
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Contact name', 'Yes', 'With the employee code it finds the contact on file (not case-sensitive).', 'Kiran Rao'],
                ['Relation', 'No', 'How the contact is related to the employee (up to 64 characters).', 'Spouse'],
                ['Phone country code', 'No', 'International dialling code without + (91 for India). With a code the phone is checked and stored as +91…; blank keeps the phone as typed.', '91'],
                ['Phone', 'To add', 'The number to call first. Blank on a contact already on file keeps the number on file.', '9876543210'],
                ['Alternate phone', 'No', 'Another number.', ''],
                ['E-mail', 'No', 'An e-mail address.', 'kiran@example.com'],
                ['Priority', 'No', '1 to 9; 1 is called first. Blank: after the contacts on file.', '1'],
            ],
            'notes' => [
                'A row finds the contact with the same name on the employee\'s profile and updates it; any other row adds a contact.',
                'Blank cells keep what is on file. Nothing is removed by an upload: remove a contact on the Employee 360 (Emergency contacts).',
            ],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';

        return [
            [$code, 'Kiran Rao', 'Spouse', '91', '9876543210', '', 'kiran@example.com', '1'],
            [$code, 'Mohan Rao', 'Father', '91', '9812345678', '0802345678', '', '2'],
        ];
    }

    /**
     * The row as ChangeEmergencyContactAction takes it (name always; the rest only when given) and what is wrong.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function parse(array $r): array
    {
        $errors = [];
        $data = ['name' => trim((string) preg_replace('/\s+/u', ' ', (string) ($r['name'] ?? '')))];
        if ($data['name'] === '') {
            $errors[] = 'Contact name is required.';
        }
        foreach (['relation', 'phone', 'alternate_phone', 'email'] as $field) {
            if (isset($r[$field])) {
                $data[$field] = trim($r[$field]);
            }
        }
        if (isset($r['phone_country_code'])) {
            $data['phone_country_code'] = ltrim(trim($r['phone_country_code']), '+');
        }
        if (isset($r['priority'])) {
            if (ctype_digit(trim($r['priority']))) {
                $data['priority'] = (int) trim($r['priority']);
            } else {
                $errors[] = 'Priority must be a whole number from 1 to 9.';
            }
        }
        if ($errors === []) {
            // Only what the row gives: a contact on file keeps the rest (adding checks the whole contact, in check()).
            $validator = Validator::make($data, array_intersect_key(app(ChangeEmergencyContactAction::class)->rules(), $data), [], ['phone_country_code' => 'phone country code', 'alternate_phone' => 'alternate phone']);
            array_push($errors, ...$validator->errors()->all());
        }

        return [$data, array_values(array_unique($errors))];
    }

    /** The contact on the employee's profile with the same name (not case-sensitive). */
    private function onFile(Employee $employee, string $name): ?PersonEmergencyContact
    {
        return PersonEmergencyContact::query()->where('person_id', $employee->person_id)->orderBy('id')->get()
            ->first(fn (PersonEmergencyContact $c) => mb_strtolower(trim((string) $c->name)) === mb_strtolower($name));
    }

    /** After the contacts on file (up to 9). */
    private function nextPriority(Employee $employee): int
    {
        return min(9, (int) PersonEmergencyContact::query()->where('person_id', $employee->person_id)->max('priority') + 1);
    }
}
