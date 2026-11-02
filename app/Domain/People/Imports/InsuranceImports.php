<?php

namespace App\Domain\People\Imports;

use App\Domain\Employment\Enums\ImportMode;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\People\Models\InsurancePolicy;
use App\Domain\People\Models\InsuredMember;
use App\Domain\People\Models\PersonFamilyMember;
use App\Domain\People\Services\InsuranceRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use League\Csv\Reader;
use RuntimeException;
use Throwable;

/**
 * HROne parity MS-5: the health insurance upload — one row per insured person (the employee or a family member already on
 * the profile) with the policy covering them — on the staged pipeline (upload → inspect → map → validate → approve → run),
 * with the "new or existing" choice of the other uploads. Every row is written through InsuranceRecords, so a file meets
 * the rules, organisation scope, audit and timeline of a change made on the Employee 360's Health insurance tab.
 *
 * Member ids never sit in clear in staging: like the bank details upload, rows are sealed (encrypted) when the file is
 * read, screens show the employee, insurer and the last four characters only, and once the upload is run or discarded the
 * sealed copies are dropped and the file is deleted.
 */
final class InsuranceImports extends RowImports
{
    public const TYPE = 'health_insurance';

    /** Who may upload: bulk import (employee.import) of sensitive employee data (what the 360 tab needs to change it). */
    public const PERMISSION = InsuranceRecords::PERMISSION;

    public const MODES = [
        'new' => ['Add insured people not on file', 'Adds the people (and their policy, when it is new) not on a policy yet. People already on the policy are skipped and the policy on file is kept.'],
        'update' => ['Update insured people on file', 'Changes the people already on the policy and the policy\'s details. People not on file are skipped.'],
        'both' => ['Both', 'Adds the people not on file and updates the ones on file, with the policy\'s details.'],
    ];

    private const YES = ['yes', 'y', 'true', '1'];

    private const NO = ['no', 'n', 'false', '0'];

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'insurer_name' => ['Insurance company', true], 'insurer_code' => ['Company code', false],
            'policy_number' => ['Policy number', true], 'sum_assured' => ['Sum assured', false], 'issued_on' => ['Issue date', false], 'valid_until' => ['Valid until', false],
            'insured' => ['Insured person', false], 'relation' => ['Relation', false], 'member_id' => ['Member id', false], 'date_of_birth' => ['Date of birth', false],
            'gender' => ['Gender', false], 'is_parents_cover' => ['Parents cover', false],
        ];
    }

    public function label(): string
    {
        return 'Health insurance upload';
    }

    protected function module(): string
    {
        return 'people';
    }

    protected function entityType(): string
    {
        return InsuredMember::class;
    }

    protected function aliases(): array
    {
        return ['insurer' => 'insurer_name', 'insurance_company_name' => 'insurer_name', 'company_name' => 'insurer_name', 'insurance_company_code' => 'insurer_code',
            'policy_no' => 'policy_number', 'policy' => 'policy_number', 'insurance_code' => 'member_id', 'member_no' => 'member_id', 'member' => 'insured',
            'insuree_name' => 'insured', 'name' => 'insured', 'relationship' => 'relation', 'assured_sum' => 'sum_assured', 'issue_date' => 'issued_on',
            'valid_upto' => 'valid_until', 'insuree_date_of_birth' => 'date_of_birth', 'dob' => 'date_of_birth', 'insuree_gender' => 'gender',
            'parents_mediclaim' => 'is_parents_cover', 'parents_cover' => 'is_parents_cover'];
    }

    /** Read the file into sealed rows (the member id is in them), never into the clear `data` column. */
    public function inspect(EmployeeImport $import): EmployeeImport
    {
        $this->assert($import, ['uploaded', 'inspected']);
        $max = (int) config('peopleos.people.import_max_rows', 5000);

        $csv = Reader::from(Storage::disk($import->disk)->readStream($import->path));
        $csv->setHeaderOffset(0);
        $headers = array_values(array_map(fn ($h) => trim((string) $h), $csv->getHeader()));
        if ($headers === [] || count(array_filter($headers)) !== count(array_unique(array_filter($headers)))) {
            throw new RuntimeException('The file needs a header row with unique column names.');
        }

        DB::transaction(function () use ($import, $csv, $headers, $max) {
            $import->rows()->delete();
            $count = 0;
            foreach ($csv->getRecords() as $record) {
                if (++$count > $max) {
                    throw new RuntimeException("The file has more than {$max} rows; split it.");
                }
                EmployeeImportRow::create(['employee_import_id' => $import->getKey(), 'row_number' => $count, 'data' => [], 'sealed_data' => array_map(fn ($v) => is_string($v) ? trim($v) : $v, $record)]);
            }
            $import->update(['headers' => $headers, 'row_count' => $count, 'mapping' => $import->mapping ?: $this->suggestMapping($headers), 'status' => 'inspected']);
        });

        return $import->refresh();
    }

    public function run(EmployeeImport $import, User $user): EmployeeImport
    {
        try {
            return parent::run($import, $user);
        } finally {
            $this->forget($import);
        }
    }

    public function discard(EmployeeImport $import, ?string $reason = null): EmployeeImport
    {
        $import = parent::discard($import, $reason);
        $this->forget($import);

        return $import;
    }

    /** One line naming a row without its member id: code, name, insurer, insured person and the last four characters. */
    public function summary(EmployeeImportRow $row, EmployeeImport $import): string
    {
        $label = $row->match['label'] ?? null;
        if ($label === null && $row->sealed_data !== null) {
            $label = $this->rowLabel($this->recordFor($row, $import->mapping ?? []), null);
        }

        return $label ?: "Row {$row->row_number}";
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        [$policyData, $memberData, $problems] = $this->parse($r);
        $errors = [...$errors, ...$problems];
        $employee = $employeeId === null ? null : Employee::query()->with('person')->find($employeeId);
        $source = null;
        if ($employee !== null) {
            [$source, $problem] = $this->insuredPerson($employee, $r);
            if ($problem !== null) {
                $errors[] = $problem;
            }
        }
        if ($errors !== [] || $employee === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        $key = InsuredMember::keyFor($source?->getKey());
        $seenKey = $employeeId.'|'.$policyData['policy_number'].'|'.$key;
        if (isset($context['seen'][$seenKey])) {
            return ['action' => 'error', 'errors' => ["The same person on the same policy is also on row {$context['seen'][$seenKey]}."], 'employee_id' => $employeeId];
        }
        $context['seen'][$seenKey] = $row->row_number;

        [$policy, $member] = $this->onFile($employee, $policyData['policy_number'], $key);
        $match = ['employee_id' => $employeeId, 'employee_code' => $employee->employee_code, 'name' => $employee->person?->full_name, 'matched_on' => ['employee code'],
            'label' => $this->rowLabel($r, $employee)];
        if ($member === null) {
            return ['action' => 'create', 'match' => $match, 'employee_id' => $employeeId];
        }
        $same = $this->sameAs($policy, $member, $policyData, $memberData);

        return $same
            ? ['action' => 'skip', 'match' => $match + ['reason' => 'Already on file as given.'], 'employee_id' => $employeeId]
            : ['action' => 'update', 'match' => $match, 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = Employee::query()->with('person')->findOrFail($row->employee_id);
        [$policyData, $memberData, $problems] = $this->parse($r);
        if ($problems !== []) {
            throw new ProfileChangeRefused(implode(' ', $problems));
        }
        [$source, $problem] = $this->insuredPerson($employee, $r);
        if ($problem !== null) {
            throw new ProfileChangeRefused($problem);
        }
        [$policy, $member] = $this->onFile($employee, $policyData['policy_number'], InsuredMember::keyFor($source?->getKey()));
        // "New only" adds people and leaves a policy already on file as it is; otherwise the policy's given details change too.
        if ($policy !== null && ImportMode::of($import) === ImportMode::New) {
            $policyData = [];
        }
        $memberData['family_member_id'] = $source?->getKey();
        $memberData['record_id'] = $member?->getKey();
        if ($member !== null) {
            // Blank cells keep what is on file (not what the profile says).
            $memberData += ['date_of_birth' => $member->date_of_birth?->toDateString(), 'gender' => $member->gender, 'is_parents_cover' => (bool) $member->is_parents_cover];
        }
        $origin = new ChangeOrigin('bulk_upload', $this->label()." #{$import->getKey()}", null);
        $saved = app(InsuranceRecords::class)->save($employee, $policy, $policyData, [$memberData], $user, $this->label().' '.$import->original_name, $origin);
        $who = $source?->name ?? (string) ($employee->person?->full_name ?? $employee->employee_code);

        return ($member === null ? 'Added ' : 'Updated ').$who.' on '.$saved->insurer_name.' '.$saved->policy_number;
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Insurance company', 'Yes', 'The insurer’s name.', 'Star Health'],
                ['Company code', 'No', 'The insurer’s code, if you use one.', 'STAR'],
                ['Policy number', 'Yes', 'The policy covering the person; one policy number per employee.', 'GHI/2026/001'],
                ['Sum assured', 'No', 'The cover amount (digits; commas are ignored).', '500000'],
                ['Issue date', 'No', 'YYYY-MM-DD, DD-MM-YYYY or DD/MM/YYYY.', '2026-04-01'],
                ['Valid until', 'No', 'The last day of cover; reminders go out before it.', '2027-03-31'],
                ['Insured person', 'No', 'Blank or "Self" for the employee; otherwise a family member\'s name exactly as on the profile.', 'Self'],
                ['Relation', 'No', 'The family member\'s relation, when two have the same name.', 'spouse'],
                ['Member id', 'No', 'The insurer\'s member id (stored encrypted). Blank keeps the one on file.', 'STAR-100245'],
                ['Date of birth', 'No', 'Blank: as on the profile.', ''],
                ['Gender', 'No', 'male, female, non_binary or undisclosed (or the label). Blank: as on the profile.', ''],
                ['Parents cover', 'No', 'yes or no: the family member is under the parents\' cover.', 'no'],
            ],
            'notes' => [
                'Add family members on the Employee 360 (or the employee import) first; this upload only links them to a policy.',
                'Blank cells keep what is on file. Nobody is removed by an upload: remove people on the Health insurance tab.',
                'Member ids are stored encrypted while the file is staged; the file and the staged copies are deleted once the upload is run or discarded.',
            ],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';

        return [[$code, 'Star Health', 'STAR', 'GHI/2026/001', '500000', now()->startOfMonth()->toDateString(), now()->startOfMonth()->addYear()->subDay()->toDateString(), 'Self', '', 'STAR-100245', '', '', 'no']];
    }

    /** @return array<string, string> field => non-empty value, read from the sealed copy */
    protected function recordFor(EmployeeImportRow $row, array $mapping): array
    {
        $r = [];
        foreach ($mapping as $header => $field) {
            $v = $row->sealed_data[$header] ?? null;
            $v = is_string($v) ? trim($v) : $v;
            if ($v !== null && $v !== '') {
                $r[$field] = (string) $v;
            }
        }

        return $r;
    }

    // ---------------------------------------------------------------------------------------

    /**
     * The row as InsuranceRecords takes it: policy fields (only those given) and member fields, with what is wrong.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: list<string>}
     */
    private function parse(array $r): array
    {
        $errors = [];
        $policy = array_intersect_key($r, array_flip(InsuranceRecords::POLICY_FIELDS));
        foreach (['issued_on' => 'Issue date', 'valid_until' => 'Valid until'] as $field => $label) {
            if (isset($policy[$field])) {
                $date = $this->date($policy[$field]);
                $date === null ? $errors[] = "{$label} must be a date (YYYY-MM-DD, DD-MM-YYYY or DD/MM/YYYY)." : $policy[$field] = $date;
            }
        }
        if (isset($policy['sum_assured'])) {
            $policy['sum_assured'] = str_replace([',', ' ', "\u{00A0}"], '', $policy['sum_assured']);
        }
        foreach (['insurer_code', 'policy_number'] as $field) {
            if (isset($policy[$field])) {
                $policy[$field] = mb_strtoupper($policy[$field]);
            }
        }
        $rules = app(InsuranceRecords::class)->policyRules();
        $validator = Validator::make($policy, array_intersect_key($rules, $policy + ['insurer_name' => true, 'policy_number' => true]), [],
            ['insurer_name' => 'insurance company', 'insurer_code' => 'company code', 'policy_number' => 'policy number', 'sum_assured' => 'sum assured', 'issued_on' => 'issue date', 'valid_until' => 'valid until']);
        array_push($errors, ...$validator->errors()->all());
        if (isset($policy['issued_on'], $policy['valid_until']) && $policy['valid_until'] < $policy['issued_on']) {
            $errors[] = 'Valid until is before the issue date.';
        }

        $member = [];
        if (isset($r['member_id'])) {
            $member['member_id'] = mb_strtoupper((string) preg_replace('/\s+/', '', $r['member_id']));
            if (preg_match('/^\d+(\.\d+)?e\+?\d+$/i', $member['member_id'])) {
                $errors[] = 'The member id was turned into a number like 5.01E+13 by the spreadsheet; format the column as Text and enter it again.';
            }
        }
        if (isset($r['date_of_birth'])) {
            $date = $this->date($r['date_of_birth']);
            $date === null ? $errors[] = 'Date of birth must be a date (YYYY-MM-DD, DD-MM-YYYY or DD/MM/YYYY).' : $member['date_of_birth'] = $date;
        }
        if (isset($r['gender'])) {
            $genders = (array) config('peopleos.people.genders', []);
            $value = mb_strtolower($r['gender']);
            $gender = array_key_exists($value, $genders) ? $value : array_search($value, array_map('mb_strtolower', $genders), true);
            $gender === false ? $errors[] = 'Gender must be one of: '.implode(', ', array_keys($genders)).'.' : $member['gender'] = $gender;
        }
        if (isset($r['is_parents_cover'])) {
            $value = mb_strtolower($r['is_parents_cover']);
            in_array($value, [...self::YES, ...self::NO], true) ? $member['is_parents_cover'] = in_array($value, self::YES, true) : $errors[] = 'Parents cover must be yes or no.';
        }
        $validator = Validator::make($member, array_intersect_key(app(InsuranceRecords::class)->memberRules(), $member), [], ['member_id' => 'member id', 'date_of_birth' => 'date of birth']);
        array_push($errors, ...$validator->errors()->all());

        return [$policy, $member, array_values(array_unique($errors))];
    }

    /**
     * The person the row covers: null for the employee (blank or "self"), else the one family member of that name (and
     * relation, when given) on the employee's own profile.
     *
     * @return array{0: ?PersonFamilyMember, 1: ?string}
     */
    private function insuredPerson(Employee $employee, array $r): array
    {
        $name = trim((string) ($r['insured'] ?? ''));
        $relation = isset($r['relation']) ? mb_strtolower(trim($r['relation'])) : null;
        if ($name === '' || in_array(mb_strtolower($name), ['self', 'employee'], true) || $relation === InsuredMember::SELF) {
            return [null, null];
        }
        $matches = PersonFamilyMember::query()->where('person_id', $employee->person_id)->get()
            ->filter(fn (PersonFamilyMember $m) => mb_strtolower(trim((string) $m->name)) === mb_strtolower($name) && ($relation === null || mb_strtolower((string) $m->relation) === $relation))->values();
        if ($matches->count() === 1) {
            return [$matches->first(), null];
        }

        return [null, $matches->isEmpty()
            ? "No family member named '{$name}'".($relation ? " ({$relation})" : '')." is on {$employee->employee_code}'s profile: add them first."
            : "{$employee->employee_code} has more than one family member named '{$name}': give the relation."];
    }

    /** @return array{0: ?InsurancePolicy, 1: ?InsuredMember} the policy of that number and the member on it, when on file */
    private function onFile(Employee $employee, string $policyNumber, string $key): array
    {
        $policy = InsurancePolicy::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->getKey())->where('policy_number', $policyNumber)->first();
        $member = $policy === null ? null : InsuredMember::query()->withoutGlobalScope(AccessScope::class)->where('policy_id', $policy->getKey())->where('member_key', $key)->first();

        return [$policy, $member];
    }

    /** Whether the row only repeats what is on file (every given value equal). */
    private function sameAs(InsurancePolicy $policy, InsuredMember $member, array $policyData, array $memberData): bool
    {
        foreach ($policyData as $field => $value) {
            $current = $policy->getAttribute($field);
            $current = $current instanceof \DateTimeInterface ? $current->format('Y-m-d') : $current;
            if ($field === 'sum_assured' ? (float) $current !== (float) $value : (string) $current !== (string) $value) {
                return false;
            }
        }
        foreach ($memberData as $field => $value) {
            $current = $field === 'member_id' ? $member->member_id : $member->getAttribute($field);
            $current = $current instanceof \DateTimeInterface ? $current->format('Y-m-d') : $current;
            if (is_bool($value) ? (bool) $current !== $value : (string) $current !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    private function rowLabel(array $r, ?Employee $employee): string
    {
        $member = isset($r['member_id']) ? '••••'.mb_substr(preg_replace('/\s+/', '', (string) $r['member_id']), -4) : null;

        return collect([$employee?->employee_code ?? ($r['employee_code'] ?? null), $employee?->person?->full_name, trim(($r['insurer_name'] ?? '').' '.mb_strtoupper((string) ($r['policy_number'] ?? ''))),
            ($r['insured'] ?? null) ?: 'Self', $member])->filter()->implode(' · ');
    }

    /** Drop the clear-text sources once the upload is finished with: the sealed rows and the file. */
    private function forget(EmployeeImport $import): void
    {
        $import->rows()->toBase()->update(['sealed_data' => null]);
        try {
            Storage::disk($import->disk)->delete($import->path);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
