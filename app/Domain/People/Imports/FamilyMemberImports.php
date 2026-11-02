<?php

namespace App\Domain\People\Imports;

use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Imports\Concerns\WorkforceRows;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\ChangeFamilyMemberAction;
use App\Domain\People\Models\PersonFamilyMember;
use Illuminate\Support\Facades\Validator;

/**
 * Data import DI-2 (Workforce → Family details, Children, Dependents): one row per family member of an employee, written
 * through ChangeFamilyMemberAction, so a file meets the rules, organisation scope, audit and timeline of a change on the
 * Employee 360's "Family & nominees" tab. A row matches the member on file with the same relation and name (not
 * case-sensitive) and updates it; any other row adds a member. Blank cells keep what is on file; nobody is removed.
 *
 * Relations are the organisation's list (codes or labels) plus everyday words: husband / wife → spouse, child → son or
 * daughter and parent → father or mother by the gender (or the member already on file under that name).
 */
final class FamilyMemberImports extends RowImports
{
    use WorkforceRows;

    public const TYPE = 'family_members';

    /** Everyday words => the relation code they mean (used when the organisation's list has that code). */
    private const WORDS = ['husband' => 'spouse', 'wife' => 'spouse', 'partner' => 'spouse', 'dad' => 'father', 'papa' => 'father', 'mom' => 'mother',
        'mum' => 'mother', 'mummy' => 'mother', 'boy' => 'son', 'girl' => 'daughter'];

    /** Words that name a relation by gender: word => [the code for a male, the code for a female]. */
    private const BY_GENDER = ['child' => ['son', 'daughter'], 'children' => ['son', 'daughter'], 'kid' => ['son', 'daughter'],
        'parent' => ['father', 'mother'], 'sibling' => ['brother', 'sister']];

    private const BOOLEANS = ['is_dependent' => 'Dependent', 'in_school' => 'In school', 'in_hostel' => 'In a hostel', 'is_nominee' => 'Nominee'];

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'relation' => ['Relation', true], 'name' => ['Name', true], 'date_of_birth' => ['Date of birth', false],
            'gender' => ['Gender', false], 'is_dependent' => ['Dependent', false], 'occupation' => ['Occupation', false], 'phone' => ['Phone', false],
            'in_school' => ['In school', false], 'in_hostel' => ['In a hostel', false], 'is_nominee' => ['Nominee', false], 'nominee_share' => ['Nominee share %', false],
            'remarks' => ['Remarks', false],
        ];
    }

    public function label(): string
    {
        return 'Family members upload';
    }

    protected function module(): string
    {
        return 'people';
    }

    protected function entityType(): string
    {
        return PersonFamilyMember::class;
    }

    protected function aliases(): array
    {
        return ['relationship' => 'relation', 'member_name' => 'name', 'family_member' => 'name', 'dependent_name' => 'name', 'child_name' => 'name',
            'dob' => 'date_of_birth', 'birth_date' => 'date_of_birth', 'sex' => 'gender', 'is_dependent' => 'is_dependent', 'dependant' => 'is_dependent',
            'profession' => 'occupation', 'mobile' => 'phone', 'contact_number' => 'phone', 'phone_number' => 'phone', 'studying' => 'in_school',
            'hostel' => 'in_hostel', 'nominee_share' => 'nominee_share', 'share' => 'nominee_share', 'remark' => 'remarks'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $employee = $employeeId === null ? null : Employee::query()->with('person')->find($employeeId);
        [$data, $problems] = $this->parse($r, $employee);
        $errors = [...$errors, ...$problems];
        if ($errors !== [] || $employee === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        $key = $employeeId.'|'.$data['relation'].'|'.mb_strtolower($data['name']);
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ["The same family member is in row {$context['seen'][$key]}."], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;

        $member = $this->onFile($employee, $data['relation'], $data['name']);
        if ($member !== null) {
            $data['name'] = $member->name; // the name is the key: a match not by case keeps the name on file
        }
        if (($refusal = $this->refusal($import, $employee, $member === null ? 'employee.create' : 'employee.update')) !== null) {
            return ['action' => 'error', 'errors' => [$refusal], 'employee_id' => $employeeId];
        }
        $match = ['employee_id' => $employeeId, 'employee_code' => $employee->employee_code, 'name' => $employee->person?->full_name,
            'matched_on' => $member === null ? ['employee code'] : ['employee code', 'relation', 'name']];
        if ($member === null) {
            return ['action' => 'create', 'match' => $match, 'employee_id' => $employeeId];
        }
        $changed = array_keys(array_filter($data, fn ($value, $field) => ! self::sameValue($member->getAttribute($field), $value), ARRAY_FILTER_USE_BOTH));

        return $changed === []
            ? ['action' => 'skip', 'match' => $match + ['reason' => 'Already on file as given.'], 'employee_id' => $employeeId]
            : ['action' => 'update', 'match' => $match + ['changes' => $changed], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = $this->employeeForRun($row->employee_id);
        [$data, $problems] = $this->parse($r, $employee);
        if ($problems !== []) {
            throw new ProfileChangeRefused(implode(' ', $problems));
        }
        $member = $this->onFile($employee, $data['relation'], $data['name']);
        if ($member !== null) {
            $data['name'] = $member->name;
        }
        $action = app(ChangeFamilyMemberAction::class);
        $saved = $member === null
            ? $action->add($employee, $data, $user, $this->why($import), $this->origin($import))
            : $action->update($employee, $member, $data, $user, $this->why($import), $this->origin($import));

        return ($member === null ? 'Added ' : 'Updated ').$saved->auditLabel().' for '.$employee->employee_code;
    }

    public function format(): array
    {
        $relations = app(OptionLists::class)->options('family_relations');

        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Relation', 'Yes', 'A relation from the list below (code or name). Also: husband / wife (spouse), child (son or daughter by the gender), parent (father or mother by the gender).', 'spouse'],
                ['Name', 'Yes', 'The family member\'s full name; with the relation it finds the member on file.', 'Kiran Rao'],
                ['Date of birth', 'No', 'YYYY-MM-DD, DD-MM-YYYY or DD/MM/YYYY; not in the future.', '1992-03-14'],
                ['Gender', 'No', 'male, female, non_binary or undisclosed (or the label).', 'female'],
                ['Dependent', 'No', 'yes or no: the member depends on the employee.', 'yes'],
                ['Occupation', 'No', 'What the member does (up to 120 characters).', 'Teacher'],
                ['Phone', 'No', 'A contact number (up to 32 characters).', '9876543210'],
                ['In school', 'No', 'yes or no (for children studying at a school or college).', ''],
                ['In a hostel', 'No', 'yes or no (for children living in a hostel while studying).', ''],
                ['Nominee', 'No', 'yes or no. Nominations for PF, gratuity and other benefits are set with the Nominee details upload.', ''],
                ['Nominee share %', 'No', 'A number from 0 to 100.', ''],
                ['Remarks', 'No', 'Anything else (up to 255 characters).', ''],
            ],
            'codes' => $relations,
            'codes_title' => 'Relations',
            'notes' => [
                'A row finds the family member on file with the same employee code, relation and name (not case-sensitive) and updates it; any other row adds a family member.',
                'Blank cells keep what is on file. Nobody is removed by an upload: remove a family member on the Employee 360 (Family & nominees).',
                'Each change is checked and recorded as on the Employee 360: adding needs employee.create, changing employee.update, within your organisation scope; it is audited and shown on the employee\'s timeline.',
            ],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';

        return [
            [$code, 'spouse', 'Kiran Rao', '1992-03-14', 'female', 'yes', 'Teacher', '9876543210', '', '', '', '', ''],
            [$code, 'mother', 'Lakshmi Rao', '1965-07-02', 'female', 'yes', 'Homemaker', '', '', '', '', '', ''],
            [$code, 'son', 'Arjun Rao', '2018-11-20', 'male', 'yes', 'Student', '', 'yes', 'no', '', '', 'Class 2'],
        ];
    }

    // ---------------------------------------------------------------------------------------

    /**
     * The row as ChangeFamilyMemberAction takes it (name and relation always; other fields only when given) and what is wrong.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function parse(array $r, ?Employee $employee): array
    {
        $errors = [];
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($r['name'] ?? '')));
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        $gender = $this->genderCode($r['gender'] ?? null, $errors);
        $relation = isset($r['relation']) ? $this->relation($r['relation'], $gender, $employee, $name, $errors) : null;
        if (! isset($r['relation'])) {
            $errors[] = 'Relation is required.';
        }
        $data = ['name' => $name, 'relation' => $relation];
        if ($gender !== null) {
            $data['gender'] = $gender;
        }
        if (($dob = $this->dateCell($r['date_of_birth'] ?? null, 'Date of birth', $errors)) !== null) {
            $data['date_of_birth'] = $dob;
        }
        foreach (self::BOOLEANS as $field => $label) {
            if (($flag = $this->yesNo($r[$field] ?? null, $label, $errors)) !== null) {
                $data[$field] = $flag;
            }
        }
        if (isset($r['nominee_share'])) {
            $share = rtrim(trim($r['nominee_share']), '% ');
            is_numeric($share) ? $data['nominee_share'] = (float) $share : $errors[] = 'Nominee share % must be a number from 0 to 100.';
        }
        foreach (['occupation', 'phone', 'remarks'] as $field) {
            if (isset($r[$field])) {
                $data[$field] = trim($r[$field]);
            }
        }
        if ($errors === []) {
            $validator = Validator::make($data, app(ChangeFamilyMemberAction::class)->rules(), [], ['date_of_birth' => 'date of birth', 'nominee_share' => 'nominee share']);
            array_push($errors, ...$validator->errors()->all());
        }

        return [$data, array_values(array_unique($errors))];
    }

    /** The relation code a cell means, or null with the reason in $errors. @param list<string> $errors */
    private function relation(string $value, ?string $gender, ?Employee $employee, string $name, array &$errors): ?string
    {
        $labels = app(OptionLists::class)->labels('family_relations');
        $code = self::codeIn($labels, $value);
        if ($code !== null) {
            return $code;
        }
        $word = mb_strtolower(trim($value));
        if (isset(self::WORDS[$word]) && array_key_exists(self::WORDS[$word], $labels)) {
            return self::WORDS[$word];
        }
        if (isset(self::BY_GENDER[$word]) && array_key_exists(self::BY_GENDER[$word][0], $labels) && array_key_exists(self::BY_GENDER[$word][1], $labels)) {
            [$male, $female] = self::BY_GENDER[$word];
            if ($gender === 'male' || $gender === 'female') {
                return $gender === 'male' ? $male : $female;
            }
            // Without a gender: the one member of that name already on file under either relation.
            $onFile = $employee === null || $name === '' ? collect() : PersonFamilyMember::query()->where('person_id', $employee->person_id)->whereIn('relation', [$male, $female])->get()
                ->filter(fn (PersonFamilyMember $m) => mb_strtolower(trim((string) $m->name)) === mb_strtolower($name))->values();
            if ($onFile->count() === 1) {
                return (string) $onFile->first()->relation;
            }
            $errors[] = "Relation '{$value}' needs the gender (male or female) to tell {$male} from {$female}; or write {$male} or {$female}.";

            return null;
        }
        $errors[] = "Relation '{$value}' is not in the list: ".implode(', ', array_keys(app(OptionLists::class)->options('family_relations'))).'.';

        return null;
    }

    /** The member on the employee's profile with this relation and name (not case-sensitive). */
    private function onFile(Employee $employee, string $relation, string $name): ?PersonFamilyMember
    {
        return PersonFamilyMember::query()->where('person_id', $employee->person_id)->where('relation', $relation)->orderBy('id')->get()
            ->first(fn (PersonFamilyMember $m) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $m->name))) === mb_strtolower($name));
    }
}
