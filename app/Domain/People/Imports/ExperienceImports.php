<?php

namespace App\Domain\People\Imports;

use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Imports\Concerns\WorkforceRows;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\ChangeExperienceAction;
use App\Domain\People\Models\PersonExperience;
use Illuminate\Support\Facades\Validator;

/**
 * Data import DI-2 (Workforce → Work experience): one row per earlier job of an employee, written through
 * ChangeExperienceAction (organisation scope, audit, timeline). A row matches the job on file with the same employer
 * (not case-sensitive) and start date and updates it; any other row adds a job. Blank cells keep what is on file;
 * nothing is removed. A person marked as a fresher has no earlier employment to add.
 */
final class ExperienceImports extends RowImports
{
    use WorkforceRows;

    public const TYPE = 'work_experience';

    public static function fields(): array
    {
        return [
            'employee_code' => ['Employee code', true], 'employer' => ['Employer', true], 'designation' => ['Designation', false], 'from_date' => ['From date', true],
            'to_date' => ['To date', false], 'location' => ['Location', false], 'reason_for_leaving' => ['Reason for leaving', false],
            'responsibilities' => ['Responsibilities', false], 'is_verified' => ['Verified', false],
        ];
    }

    public function label(): string
    {
        return 'Work experience upload';
    }

    protected function module(): string
    {
        return 'people';
    }

    protected function entityType(): string
    {
        return PersonExperience::class;
    }

    protected function aliases(): array
    {
        return ['company' => 'employer', 'company_name' => 'employer', 'organisation' => 'employer', 'organization' => 'employer', 'previous_employer' => 'employer',
            'employer_name' => 'employer', 'role' => 'designation', 'job_title' => 'designation', 'title' => 'designation', 'from' => 'from_date',
            'start_date' => 'from_date', 'date_of_joining' => 'from_date', 'joining_date' => 'from_date', 'to' => 'to_date', 'end_date' => 'to_date',
            'relieving_date' => 'to_date', 'date_of_leaving' => 'to_date', 'city' => 'location', 'leaving_reason' => 'reason_for_leaving',
            'reason' => 'reason_for_leaving', 'job_description' => 'responsibilities', 'description' => 'responsibilities'];
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
        $key = $employeeId.'|'.mb_strtolower($data['employer']).'|'.$data['from_date'];
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ["The same job is in row {$context['seen'][$key]}."], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;

        $job = $this->onFile($employee, $data);
        if ($job !== null) {
            $data['employer'] = $job->employer; // the employer is the key: a match not by case keeps the name on file
        }
        if ($job === null && $employee->person?->isFresher()) {
            return ['action' => 'error', 'errors' => ["{$employee->employee_code} is marked as a fresher (no earlier employment): untick \"Fresher\" first."], 'employee_id' => $employeeId];
        }
        if (($refusal = $this->refusal($import, $employee, $job === null ? 'employee.create' : 'employee.update')) !== null) {
            return ['action' => 'error', 'errors' => [$refusal], 'employee_id' => $employeeId];
        }
        $match = ['employee_id' => $employeeId, 'employee_code' => $employee->employee_code, 'name' => $employee->person?->full_name,
            'matched_on' => $job === null ? ['employee code'] : ['employee code', 'employer', 'from date']];
        if ($job === null) {
            return ['action' => 'create', 'match' => $match, 'employee_id' => $employeeId];
        }
        $changed = array_keys(array_filter($data, fn ($value, $field) => ! self::sameValue($job->getAttribute($field), $value), ARRAY_FILTER_USE_BOTH));

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
        $job = $this->onFile($employee, $data);
        if ($job !== null) {
            $data['employer'] = $job->employer;
        }
        $action = app(ChangeExperienceAction::class);
        $saved = $job === null
            ? $action->add($employee, $data, $user, $this->why($import), $this->origin($import))
            : $action->update($employee, $job, $data, $user, $this->why($import), $this->origin($import));

        return ($job === null ? 'Added ' : 'Updated ').$saved->auditLabel().' for '.$employee->employee_code;
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Employee code', 'Yes', "The employee's code.", 'EMP0001'],
                ['Employer', 'Yes', 'The earlier employer\'s name.', 'Infosys Ltd'],
                ['Designation', 'No', 'The job title held there.', 'Software engineer'],
                ['From date', 'Yes', 'YYYY-MM-DD, DD-MM-YYYY or DD/MM/YYYY; with the employer it finds the job on file.', '2016-07-01'],
                ['To date', 'No', 'The last day there; not before the from date, not in the future.', '2019-06-30'],
                ['Location', 'No', 'Where the job was.', 'Pune'],
                ['Reason for leaving', 'No', 'Why the employee left (up to 255 characters).', 'Better opportunity'],
                ['Responsibilities', 'No', 'What the job involved (up to 2000 characters).', 'Built payment services'],
                ['Verified', 'No', 'yes or no: the experience has been checked.', 'no'],
            ],
            'notes' => [
                'A row finds the job on file with the same employer (not case-sensitive) and from date and updates it; any other row adds a job. To correct a from date, change the job on the Employee 360.',
                'Blank cells keep what is on file. Nothing is removed by an upload: remove a job on the Employee 360 (Previous employment).',
                'A person marked as a fresher has no earlier employment: untick "Fresher" first.',
                'Income and tax from a previous employer this financial year are loaded with the Previous employment upload.',
            ],
        ];
    }

    protected function examples(): array
    {
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';

        return [
            [$code, 'Infosys Ltd', 'Software engineer', '2016-07-01', '2019-06-30', 'Pune', 'Better opportunity', 'Built payment services', 'no'],
            [$code, 'Wipro Ltd', 'Senior engineer', '2019-07-15', '2022-12-31', 'Bengaluru', 'Relocation', '', 'yes'],
        ];
    }

    /**
     * The row as ChangeExperienceAction takes it (employer and from date always; the rest only when given) and what is wrong.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function parse(array $r): array
    {
        $errors = [];
        $data = ['employer' => trim((string) preg_replace('/\s+/u', ' ', (string) ($r['employer'] ?? '')))];
        if ($data['employer'] === '') {
            $errors[] = 'Employer is required.';
        }
        $data['from_date'] = $this->dateCell($r['from_date'] ?? null, 'From date', $errors);
        if (! isset($r['from_date'])) {
            $errors[] = 'From date is required.';
        }
        if (($to = $this->dateCell($r['to_date'] ?? null, 'To date', $errors)) !== null) {
            $data['to_date'] = $to;
        }
        foreach (['designation', 'location', 'reason_for_leaving', 'responsibilities'] as $field) {
            if (isset($r[$field])) {
                $data[$field] = trim($r[$field]);
            }
        }
        if (($flag = $this->yesNo($r['is_verified'] ?? null, 'Verified', $errors)) !== null) {
            $data['is_verified'] = $flag;
        }
        if ($errors === []) {
            $validator = Validator::make($data, app(ChangeExperienceAction::class)->rules(), [], ['from_date' => 'from date', 'to_date' => 'to date', 'reason_for_leaving' => 'reason for leaving']);
            array_push($errors, ...$validator->errors()->all());
        }

        return [$data, array_values(array_unique($errors))];
    }

    /** The job on the employee's profile with the same employer (not case-sensitive) and from date. */
    private function onFile(Employee $employee, array $data): ?PersonExperience
    {
        return PersonExperience::query()->where('person_id', $employee->person_id)->whereDate('from_date', $data['from_date'])->orderBy('id')->get()
            ->first(fn (PersonExperience $job) => mb_strtolower(trim((string) $job->employer)) === mb_strtolower($data['employer']));
    }
}
