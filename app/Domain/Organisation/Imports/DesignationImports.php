<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Configuration\Applicability\ConfigurationTypes;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Employment\Imports\Workbooks\PlannedReferences;
use App\Domain\Employment\Imports\Workbooks\WorkbookImports;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Imports\Concerns\ReadsMasterRows;
use App\Domain\Organisation\Imports\Concerns\RequiresUpdatePermission;
use App\Domain\Organisation\Imports\Concerns\SetsApplicability;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Services\DesignationDefaults;
use App\Domain\Organisation\Services\LevelGradePairs;
use App\Support\Imports\ClearValue;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Data import DI-1 (Core → Designations): designations by code, with level, grade, department and job family codes,
 * the experience range (years, low to high, as the designation screen checks), the minimum-wage skill category and
 * the hazardous-work flag.
 */
final class DesignationImports extends MasterImports
{
    use ReadsMasterRows, RequiresUpdatePermission, SetsApplicability;

    public const TYPE = 'designations';

    public static function fields(): array
    {
        return [
            'code' => ['Code', true], 'name' => ['Name', true], 'level_code' => ['Level code', false], 'grade_code' => ['Grade code', false],
            'department_code' => ['Usual department (information only)', false], 'job_family_code' => ['Job family code', false],
            'min_experience_years' => ['Minimum experience (years)', false], 'max_experience_years' => ['Maximum experience (years)', false],
            'wage_skill_category' => ['Wage skill category', false], 'is_hazardous' => ['Hazardous work', false],
            'description' => ['Description', false], 'status' => ['Status', false],
        ] + self::applicabilityFields() + ['company_code' => ['Company (its own level, grade and job family)', false]];
    }

    /** Master relationships R3: what a row with a Company sets: column => [attribute, model, label]. */
    private const COMPANY_LINKS = ['level_code' => ['level_id', Level::class, 'Level'], 'grade_code' => ['grade_id', Grade::class, 'Grade'], 'job_family_code' => ['job_family_id', JobFamily::class, 'Job family']];

    /** Columns that describe the shared designation, never one company's values. */
    private const SHARED_ONLY = ['department_code', 'min_experience_years', 'max_experience_years', 'wage_skill_category', 'is_hazardous', 'description', 'status', 'applies_to', 'future_companies'];

    /**
     * Master relationships R3: a row with a Company gives that company its own level, grade and job family of a shared
     * designation (configuration overrides, from today); `(none)` ends the company's own value, so the shared one applies
     * again; blank keeps it. A row without a Company is the designation itself, as before.
     */
    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        if (blank($r['company_code'] ?? null)) {
            return parent::check($r, $context, $row, $import);
        }
        [$designation, $company, $values, $errors] = $this->companyRow($r, $context, $row, $import);
        $code = trim((string) ($r['code'] ?? ''));
        $key = 'company:'.mb_strtolower($code).'|'.$company;
        if ($errors === [] && isset($context['seen'][$key])) {
            $errors[] = "Row {$context['seen'][$key]} already gives this company's values of {$code}.";
        }
        $context['seen'][$key] = $row->row_number;
        if ($errors === [] && $designation !== null && $company > 0) {
            try {
                $uploader = User::query()->find($import->uploaded_by);
                app(ConfigurationOverrides::class)->authorise(app(ConfigurationTypes::class)->for(DesignationDefaults::TYPE), $designation, $company, today()->toDateString(), $uploader);
            } catch (ApplicabilityRefusedException $e) {
                $errors[] = $e->getMessage();
            }
            // Remediation I.6: the company's level and grade after this row must agree ("(none)" = the shared value). A pair
            // the row does not change is left to the run (data from before the rule is not refused here either); a later
            // date that would disagree is refused when the row runs, with nothing written.
            $after = app(DesignationDefaults::class)->for($designation, $company);
            $now = [$after['level_id'], $after['grade_id']];
            foreach (['level_id', 'grade_id'] as $column) {
                $id = $values[$column] ?? null;
                if ($id === self::CLEARED) {
                    $after[$column] = $designation->getAttribute($column) === null ? null : (int) $designation->getAttribute($column);
                } elseif (is_int($id) && $id > 0) {
                    $after[$column] = $id;
                }
            }
            $planned = in_array(self::PLANNED, [$values['level_id'] ?? null, $values['grade_id'] ?? null], true);
            if ($errors === [] && ! $planned && $now !== [$after['level_id'], $after['grade_id']] && ($problem = app(LevelGradePairs::class)->problem($after['level_id'], $after['grade_id'])) !== null) {
                $errors[] = "{$this->companyName($company)}: {$problem} Give the level and grade that agree.";
            }
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => array_values(array_unique($errors))];
        }
        // A company or designation an earlier worksheet of the workbook adds: the run sets the values once it is on file.
        if ($company === self::PLANNED || $designation === null) {
            return ['action' => 'update', 'match' => ['changes' => ["Company values of {$code} (added earlier in this workbook)"]]];
        }
        $changes = $this->companyChanges($designation, $company, $values);

        return $changes === []
            ? ['action' => 'skip', 'match' => ['reason' => $this->companyName($company)." already uses these values of {$code}."]]
            : ['action' => 'update', 'match' => ['changes' => $changes, 'company_id' => $company]];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        if (blank($r['company_code'] ?? null)) {
            return parent::apply($r, $row, $import, $user);
        }
        $context = [];
        [$designation, $company, $values, $errors] = $this->companyRow($r, $context, $row, $import);
        if ($designation === null || $company <= 0 || $errors !== []) {
            throw new RuntimeException(implode(' ', $errors) ?: 'The row could not be read.');
        }
        $reason = $this->label()." #{$import->getKey()} ({$import->original_name})";
        $overrides = app(ConfigurationOverrides::class);
        $own = $overrides->valuesFor(DesignationDefaults::TYPE, (int) $designation->id, $company);
        $current = app(DesignationDefaults::class)->for($designation, $company);
        $done = [];
        // Remediation I.6: the row's values change together, so a new level and grade are checked as a pair, once.
        $overrides->together(function () use ($values, $own, $current, $overrides, $designation, $company, $user, $reason, &$done) {
            foreach ($values as $column => $id) {
                $label = strtolower(DesignationDefaults::FIELDS[$column]);
                if ($id === self::CLEARED) {
                    if (array_key_exists($column, $own) && $overrides->end($designation, $company, $column, today(), $user, $reason)) {
                        $done[] = "{$label} back to the shared value";
                    }
                } elseif ($id !== $current[$column]) {
                    $overrides->set($designation, $company, $column, $id, today(), $user, $reason);
                    $done[] = "{$label} ".$this->nameOf($column, $id);
                }
            }
        });

        return $this->companyName($company)." values of {$designation->code}: ".($done === [] ? 'nothing changed' : implode(', ', $done));
    }

    /**
     * The designation, the company, the values (column => id, CLEARED to end, PLANNED for a code an earlier worksheet adds)
     * and what is wrong with a row that names a company.
     *
     * @return array{0: ?Designation, 1: int, 2: array<string, int>, 3: list<string>}
     */
    private function companyRow(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        $errors = [];
        $code = trim((string) ($r['code'] ?? ''));
        if ($code === '') {
            return [null, 0, [], ['Code is required.']];
        }
        foreach (self::SHARED_ONLY as $field) {
            if (filled($r[$field] ?? null)) {
                $errors[] = 'A row with a Company sets only that company\'s level, grade and job family: put '.(self::fields()[$field][0] ?? $field).' on a row without Company.';

                break;
            }
        }
        $context['row_ref'] = ['sheet' => $import->sheet, 'row' => (int) ($row->data[WorkbookImports::ROW_COLUMN] ?? $row->row_number + 1)];
        $context['row_company'] = null;
        $context['row_company_seen'] = [];
        $company = (int) ($this->lookup($context, Company::class, (string) $r['company_code'], 'Company', $errors) ?? 0);
        if ($company === self::CLEARED) {
            $errors[] = 'Company cannot be '.ClearValue::NONE.' here.';
            $company = 0;
        }
        $context['row_company'] = $company > 0 ? $company : null;
        $designation = $this->find($code);
        if ($designation === null && ! isset($context['seen'][mb_strtolower($code)]) && app(PlannedReferences::class)->plannedRecord(Designation::class, $code) === null) {
            $errors[] = "Designation '{$code}' is not on file: add it with a row without Company first (or earlier in this file).";
        }
        $values = [];
        foreach (self::COMPANY_LINKS as $field => [$column, $class, $label]) {
            $id = $this->lookup($context, $class, $r[$field] ?? null, $label, $errors);
            if ($id !== null) {
                $values[$column] = (int) $id;
            }
        }
        if ($values === [] && $errors === []) {
            $errors[] = 'Give the company\'s level, grade or job family, or '.ClearValue::NONE.' to end its own value.';
        }

        return [$designation instanceof Designation ? $designation : null, $company, $values, $errors];
    }

    /** @return list<string> "FynnEdge Advisory: grade T5", for the preview */
    private function companyChanges(?Designation $designation, int $company, array $values): array
    {
        $name = $this->companyName($company);
        $own = $designation === null ? [] : app(ConfigurationOverrides::class)->valuesFor(DesignationDefaults::TYPE, (int) $designation->id, $company);
        $current = $designation === null ? array_fill_keys(array_keys(DesignationDefaults::FIELDS), null) : app(DesignationDefaults::class)->for($designation, $company);
        $changes = [];
        foreach ($values as $column => $id) {
            $label = strtolower(DesignationDefaults::FIELDS[$column]);
            if ($id === self::CLEARED) {
                if (array_key_exists($column, $own)) {
                    $changes[] = "{$name}: {$label} back to the shared value";
                }
            } elseif ($id === self::PLANNED || $id !== $current[$column]) {
                $changes[] = "{$name}: {$label} ".($id === self::PLANNED ? '(added earlier in this workbook)' : $this->nameOf($column, $id));
            }
        }

        return $changes;
    }

    private function companyName(int $company): string
    {
        return (string) (Company::query()->withoutGlobalScope(AccessScope::class)->whereKey($company)->value('name') ?? 'the company');
    }

    private function nameOf(string $column, int $id): string
    {
        $class = collect(self::COMPANY_LINKS)->first(fn (array $link) => $link[0] === $column)[1];

        return (string) ($class::query()->withoutGlobalScope(AccessScope::class)->whereKey($id)->value('name') ?? '#'.$id);
    }

    public function label(): string
    {
        return 'Designations upload';
    }

    protected function updatePermission(): string
    {
        return 'people_setup.update';
    }

    protected function module(): string
    {
        return 'organisation';
    }

    protected function model(): string
    {
        return Designation::class;
    }

    protected function aliases(): array
    {
        return ['designation_code' => 'code', 'designation_name' => 'name', 'designation' => 'name', 'level' => 'level_code', 'grade' => 'grade_code', 'department' => 'department_code',
            'job_family' => 'job_family_code', 'min_experience' => 'min_experience_years', 'max_experience' => 'max_experience_years', 'hazardous' => 'is_hazardous',
            'skill_category' => 'wage_skill_category', 'company' => 'company_code', 'for_company' => 'company_code'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $min = $this->number($r['min_experience_years'] ?? null, 'Minimum experience (years)', $errors, 0, 99);
        $max = $this->number($r['max_experience_years'] ?? null, 'Maximum experience (years)', $errors, 0, 99);
        $low = $min ?? ($existing?->getRawOriginal('min_experience_years') !== null ? (float) $existing->getRawOriginal('min_experience_years') : null);
        $high = $max ?? ($existing?->getRawOriginal('max_experience_years') !== null ? (float) $existing->getRawOriginal('max_experience_years') : null);
        if ($low !== null && $high !== null && $high < $low) {
            $errors[] = 'The experience range runs from a minimum to a higher maximum, in years.';
        }

        $level = $this->lookup($context, Level::class, $r['level_code'] ?? null, 'Level code', $errors);
        $grade = $this->lookup($context, Grade::class, $r['grade_code'] ?? null, 'Grade code', $errors);
        // Remediation I.6: the level and grade must agree (blank keeps the one on file, "(none)" clears it; a code an
        // earlier worksheet adds is checked when the row runs).
        $pairLevel = $level > 0 ? $level : ($level === null && $existing !== null ? $existing->getAttribute('level_id') : null);
        $pairGrade = $grade > 0 ? $grade : ($grade === null && $existing !== null ? $existing->getAttribute('grade_id') : null);
        if ($errors === [] && ! in_array(self::PLANNED, [$level, $grade], true) && ($problem = app(LevelGradePairs::class)->designationFormProblem($existing instanceof Designation ? $existing : null, $pairLevel, $pairGrade)) !== null) {
            $errors[] = $problem;
        }

        return array_filter([
            'name' => $this->text($r['name'] ?? null, 255, 'Name', $errors),
            'level_id' => $level,
            'grade_id' => $grade,
            'department_id' => $this->lookup($context, Department::class, $r['department_code'] ?? null, 'Usual department', $errors),
            'job_family_id' => $this->lookup($context, JobFamily::class, $r['job_family_code'] ?? null, 'Job family code', $errors),
            'min_experience_years' => $min,
            'max_experience_years' => $max,
            'wage_skill_category' => $this->choice($r['wage_skill_category'] ?? null, (array) config('peopleos.compliance.wage_skill_categories', []), 'Wage skill category', $errors),
            'is_hazardous' => $this->flag($r['is_hazardous'] ?? null, 'Hazardous work', $errors),
            'description' => $this->text($r['description'] ?? null, 1000, 'Description', $errors),
            'status' => $this->status($r['status'] ?? null, $errors),
        ], fn ($v) => $v !== null);
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Code', 'Yes', 'The designation code (up to 32 characters). A code on file updates that designation.', 'SE'],
                ['Name', 'Yes', 'The designation name.', 'Software engineer'],
                ['Level code', 'No', 'Code of its level (People setup → Levels). '.ClearValue::hint(), 'L2'],
                ['Grade code', 'No', 'Code of its grade (People setup → Grades). '.ClearValue::hint(), 'G3'],
                ['Usual department (information only)', 'No', 'Information only: it never places anyone. Code of its department (Organisation → Departments). '.ClearValue::hint(), 'ENG'],
                ['Job family code', 'No', 'Code of its job family (People setup → Job families). '.ClearValue::hint(), 'TECH'],
                ['Minimum experience (years)', 'No', 'Years, 0 to 99 (halves allowed).', '2'],
                ['Maximum experience (years)', 'No', 'Years, 0 to 99, not below the minimum.', '5'],
                ['Wage skill category', 'No', 'For minimum-wage checks: '.implode(', ', array_keys((array) config('peopleos.compliance.wage_skill_categories', []))).'.', 'skilled'],
                ['Hazardous work', 'No', 'yes or no.', 'no'],
                ['Description', 'No', 'A short description.', 'Builds and maintains product features'],
                ['Status', 'No', 'active or inactive. Blank for a new one is active.', 'active'],
                ...self::applicabilityColumns(),
                ['Company (its own level, grade and job family)', 'No', 'Blank: the row is the designation itself. A company code or name: the row gives only that company its own level, grade and job family of the designation (from today; the shared values stay for everyone else). '.ClearValue::NONE.' ends that company\'s own value, so the shared one applies again; blank keeps it. Leave the other columns blank on such a row.', ''],
            ],
            'notes' => [
                'Rows are matched on Code (any case). A new code adds a designation; a code on file updates it; a row whose values are already on file is skipped.',
                'Blank cells keep what is on file. The job description and employment types are kept on the designation screen.',
                'Changes to an existing designation follow the Configuration Change Centre when your organisation approves configuration changes.',
            ],
        ];
    }

    protected function examples(): array
    {
        $level = Level::query()->orderBy('id')->value('code') ?? '';
        $grade = Grade::query()->orderBy('id')->value('code') ?? '';
        $department = Department::query()->withoutGlobalScope(AccessScope::class)->orderBy('id')->value('code') ?? '';

        return [
            ['SE', 'Software engineer', $level, $grade, $department, '', '2', '5', 'skilled', 'no', 'Builds and maintains product features', 'active'],
            ['TL', 'Team lead', $level, $grade, $department, '', '6', '10', 'highly_skilled', 'no', 'Leads a delivery team', 'active'],
        ];
    }
}
