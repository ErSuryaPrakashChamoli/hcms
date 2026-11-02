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
use App\Domain\People\Actions\ChangeQualificationAction;
use App\Domain\People\Models\PersonQualification;
use App\Domain\People\Models\Qualification;
use App\Domain\People\Models\QualificationSpecialisation;
use Illuminate\Support\Facades\Validator;

/**
 * Data import DI-2 (Workforce → Education): one row per qualification of an employee, written through
 * ChangeQualificationAction (organisation scope, audit, timeline). The qualification and specialisation are matched to
 * the qualification masters (Setup → Qualifications) by name, not case-sensitive; a name that is not a master is kept as
 * typed, as the Education tab allows. A row matches the entry on file with the same qualification (and specialisation,
 * when given) and updates it; any other row adds an entry. Blank cells keep what is on file; nothing is removed.
 */
final class QualificationImports extends RowImports
{
    use WorkforceRows;

    public const TYPE = 'qualifications';

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'qualification' => ['Qualification', true], 'specialisation' => ['Specialisation', false],
            'institution' => ['Institute', false], 'board_or_university' => ['Board or university', false], 'year_started' => ['Year of starting', false],
            'year_of_completion' => ['Year of passing', false], 'grade_or_score' => ['Grade or score', false], 'mode' => ['Mode of study', false],
            'is_highest' => ['Highest qualification', false], 'is_verified' => ['Verified', false],
        ];
    }

    public function label(): string
    {
        return 'Education upload';
    }

    protected function module(): string
    {
        return 'people';
    }

    protected function entityType(): string
    {
        return PersonQualification::class;
    }

    protected function aliases(): array
    {
        return ['degree' => 'qualification', 'course' => 'qualification', 'education' => 'qualification', 'stream' => 'specialisation', 'specialization' => 'specialisation',
            'major' => 'specialisation', 'institution' => 'institution', 'college' => 'institution', 'school' => 'institution', 'institute_name' => 'institution',
            'university' => 'board_or_university', 'board' => 'board_or_university', 'from_year' => 'year_started', 'start_year' => 'year_started',
            'passing_year' => 'year_of_completion', 'year_of_passing' => 'year_of_completion', 'to_year' => 'year_of_completion', 'year' => 'year_of_completion',
            'grade' => 'grade_or_score', 'score' => 'grade_or_score', 'percentage' => 'grade_or_score', 'marks' => 'grade_or_score', 'cgpa' => 'grade_or_score',
            'mode' => 'mode', 'study_mode' => 'mode', 'highest' => 'is_highest', 'is_highest' => 'is_highest'];
    }

    protected function prepare(EmployeeImport $import): array
    {
        return parent::prepare($import) + ['masters' => Qualification::query()->with('specialisations')->get()];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $employee = $employeeId === null ? null : Employee::query()->with('person')->find($employeeId);
        [$data, $problems] = $this->parse($r, $context['masters']);
        $errors = [...$errors, ...$problems];
        if ($errors !== [] || $employee === null) {
            return ['action' => 'error', 'errors' => $errors ?: ['The row could not be read.'], 'employee_id' => $employeeId];
        }
        $key = $employeeId.'|'.mb_strtolower($data['qualification']).'|'.mb_strtolower((string) ($data['specialisation'] ?? ''));
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ["The same qualification is in row {$context['seen'][$key]}."], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;

        [$entry, $ambiguous] = $this->onFile($employee, $data);
        if ($ambiguous !== null) {
            return ['action' => 'error', 'errors' => [$ambiguous], 'employee_id' => $employeeId];
        }
        $data = $this->keepNames($data, $entry);
        if (($refusal = $this->refusal($import, $employee, $entry === null ? 'employee.create' : 'employee.update')) !== null) {
            return ['action' => 'error', 'errors' => [$refusal], 'employee_id' => $employeeId];
        }
        $match = ['employee_id' => $employeeId, 'employee_code' => $employee->employee_code, 'name' => $employee->person?->full_name,
            'matched_on' => $entry === null ? ['employee code'] : ['employee code', 'qualification']];
        if ($entry === null) {
            return ['action' => 'create', 'match' => $match, 'employee_id' => $employeeId];
        }
        $changed = array_keys(array_filter(array_diff_key($data, ['qualification_id' => true, 'specialisation_id' => true]),
            fn ($value, $field) => ! self::sameValue($entry->getAttribute($field), $value), ARRAY_FILTER_USE_BOTH));

        return $changed === []
            ? ['action' => 'skip', 'match' => $match + ['reason' => 'Already on file as given.'], 'employee_id' => $employeeId]
            : ['action' => 'update', 'match' => $match + ['changes' => $changed], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $employee = $this->employeeForRun($row->employee_id);
        [$data, $problems] = $this->parse($r, Qualification::query()->with('specialisations')->get());
        [$entry, $ambiguous] = $this->onFile($employee, $data);
        if ($problems !== [] || $ambiguous !== null) {
            throw new ProfileChangeRefused(implode(' ', array_filter([...$problems, $ambiguous])));
        }
        $data = $this->keepNames($data, $entry);
        $action = app(ChangeQualificationAction::class);
        $saved = $entry === null
            ? $action->add($employee, $data, $user, $this->why($import), $this->origin($import))
            : $action->update($employee, $entry, $data, $user, $this->why($import), $this->origin($import));

        return ($entry === null ? 'Added ' : 'Updated ').$saved->auditLabel().' for '.$employee->employee_code;
    }

    public function format(): array
    {
        $masters = Qualification::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->limit(60)->get()
            ->mapWithKeys(fn (Qualification $q) => [(string) $q->name => (string) ($q->level ?? '')])->all();

        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Qualification', 'Yes', 'A qualification from Setup → Qualifications (its name, not case-sensitive), or any other name, kept as typed.', 'B.Tech'],
                ['Specialisation', 'No', 'A specialisation of that qualification (its name), or any other name.', 'Computer science'],
                ['Institute', 'No', 'The college or school.', 'IIT Bombay'],
                ['Board or university', 'No', 'The board or university.', 'IIT Bombay'],
                ['Year of starting', 'No', 'A year such as 2012; not after the year of passing.', '2012'],
                ['Year of passing', 'No', 'A year such as 2016.', '2016'],
                ['Grade or score', 'No', 'Grade, percentage or CGPA as printed (up to 32 characters).', '8.6 CGPA'],
                ['Mode of study', 'No', 'One of: '.implode(', ', array_keys(app(OptionLists::class)->options('qualification_modes'))).' (or the label).', 'full_time'],
                ['Highest qualification', 'No', 'yes or no; marking one moves the mark from the employee\'s other qualifications.', 'yes'],
                ['Verified', 'No', 'yes or no: the certificate has been checked.', 'no'],
            ],
            'codes' => $masters === [] ? null : $masters,
            'codes_title' => 'Qualifications on file',
            'notes' => [
                'A row finds the entry on file with the same qualification (and specialisation, when given) and updates it; any other row adds an entry.',
                'Blank cells keep what is on file. Nothing is removed by an upload: remove an entry on the Employee 360 (Education).',
                'Each change is checked and recorded as on the Employee 360: adding needs employee.create, changing employee.update, within your organisation scope; it is audited and shown on the employee\'s timeline.',
            ],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';
        $master = Qualification::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->value('name') ?? 'B.Tech';

        return [
            [$code, $master, 'Computer science', 'IIT Bombay', 'IIT Bombay', '2012', '2016', '8.6 CGPA', 'full_time', 'yes', 'no'],
            [$code, 'Class XII', '', 'Kendriya Vidyalaya', 'CBSE', '2010', '2012', '91%', 'full_time', 'no', 'no'],
        ];
    }

    // ---------------------------------------------------------------------------------------

    /**
     * The row as ChangeQualificationAction takes it (the masters' ids when the names match one) and what is wrong.
     *
     * @param  iterable<Qualification>  $masters
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function parse(array $r, iterable $masters): array
    {
        $errors = [];
        $name = trim((string) ($r['qualification'] ?? ''));
        if ($name === '') {
            $errors[] = 'Qualification is required.';
        }
        $master = collect($masters)->first(fn (Qualification $q) => mb_strtolower(trim((string) $q->name)) === mb_strtolower($name));
        $data = ['qualification' => $master?->name ?? $name, 'qualification_id' => $master?->id];
        if (isset($r['specialisation'])) {
            $specialisation = trim($r['specialisation']);
            $known = $master?->specialisations->first(fn (QualificationSpecialisation $s) => mb_strtolower(trim((string) $s->name)) === mb_strtolower($specialisation));
            $data['specialisation'] = $known?->name ?? $specialisation;
            $data['specialisation_id'] = $known?->id;
        }
        foreach (['institution', 'board_or_university', 'grade_or_score'] as $field) {
            if (isset($r[$field])) {
                $data[$field] = trim($r[$field]);
            }
        }
        foreach (['year_started' => 'Year of starting', 'year_of_completion' => 'Year of passing'] as $field => $label) {
            if (isset($r[$field])) {
                preg_match('/^\d{4}$/', trim($r[$field])) ? $data[$field] = (int) trim($r[$field]) : $errors[] = "{$label} must be a year such as 2016.";
            }
        }
        if (($mode = $this->optionCode('qualification_modes', $r['mode'] ?? null, 'Mode of study', $errors)) !== null) {
            $data['mode'] = $mode;
        }
        foreach (['is_highest' => 'Highest qualification', 'is_verified' => 'Verified'] as $field => $label) {
            if (($flag = $this->yesNo($r[$field] ?? null, $label, $errors)) !== null) {
                $data[$field] = $flag;
            }
        }
        if (isset($data['year_started'], $data['year_of_completion']) && $data['year_started'] > $data['year_of_completion']) {
            $errors[] = 'The year of starting is after the year of passing.';
        }
        if ($errors === []) {
            $validator = Validator::make($data, app(ChangeQualificationAction::class)->rules(), [], ['year_of_completion' => 'year of passing', 'year_started' => 'year of starting',
                'grade_or_score' => 'grade or score', 'board_or_university' => 'board or university']);
            array_push($errors, ...$validator->errors()->all());
        }

        return [$data, array_values(array_unique($errors))];
    }

    /** The names are the key: a match not by case keeps the names on file (a master's name always wins). */
    private function keepNames(array $data, ?PersonQualification $entry): array
    {
        if ($entry !== null && $data['qualification_id'] === null) {
            $data['qualification'] = $entry->qualification;
        }
        if ($entry !== null && array_key_exists('specialisation', $data) && ($data['specialisation_id'] ?? null) === null
            && mb_strtolower(trim((string) $entry->specialisation)) === mb_strtolower(trim((string) $data['specialisation']))) {
            $data['specialisation'] = $entry->specialisation;
        }

        return $data;
    }

    /**
     * The entry on file for the row: the one with the same qualification name and, when the row gives a specialisation,
     * the same specialisation (or the only such qualification, still without one).
     *
     * @return array{0: ?PersonQualification, 1: ?string} the entry, or why the row is ambiguous
     */
    private function onFile(Employee $employee, array $data): array
    {
        $same = fn (?string $a, ?string $b) => mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
        $entries = PersonQualification::query()->where('person_id', $employee->person_id)->orderBy('id')->get()
            ->filter(fn (PersonQualification $q) => $same($q->qualification, $data['qualification']))->values();
        if (array_key_exists('specialisation', $data)) {
            $exact = $entries->filter(fn (PersonQualification $q) => $same($q->specialisation, $data['specialisation']))->values();
            if ($exact->isNotEmpty()) {
                return [$exact->first(), null];
            }

            return [$entries->count() === 1 && blank($entries->first()->specialisation) ? $entries->first() : null, null];
        }
        if ($entries->count() > 1) {
            return [null, "{$employee->employee_code} has more than one {$data['qualification']} on file: give the specialisation."];
        }

        return [$entries->first(), null];
    }
}
