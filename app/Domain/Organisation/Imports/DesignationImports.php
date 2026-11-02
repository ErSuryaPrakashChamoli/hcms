<?php

namespace App\Domain\Organisation\Imports;

use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Imports\Concerns\ReadsMasterRows;
use App\Domain\Organisation\Imports\Concerns\RequiresUpdatePermission;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Organisation\Models\Level;
use Illuminate\Database\Eloquent\Model;

/**
 * Data import DI-1 (Core → Designations): designations by code, with level, grade, department and job family codes,
 * the experience range (years, low to high, as the designation screen checks), the minimum-wage skill category and
 * the hazardous-work flag.
 */
final class DesignationImports extends MasterImports
{
    use ReadsMasterRows, RequiresUpdatePermission;

    public const TYPE = 'designations';

    public static function fields(): array
    {
        return [
            'code' => ['Code', true], 'name' => ['Name', true], 'level_code' => ['Level code', false], 'grade_code' => ['Grade code', false],
            'department_code' => ['Department code', false], 'job_family_code' => ['Job family code', false],
            'min_experience_years' => ['Minimum experience (years)', false], 'max_experience_years' => ['Maximum experience (years)', false],
            'wage_skill_category' => ['Wage skill category', false], 'is_hazardous' => ['Hazardous work', false],
            'description' => ['Description', false], 'status' => ['Status', false],
        ];
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
            'skill_category' => 'wage_skill_category'];
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

        return array_filter([
            'name' => $this->text($r['name'] ?? null, 255, 'Name', $errors),
            'level_id' => $this->lookup($context, Level::class, $r['level_code'] ?? null, 'Level code', $errors),
            'grade_id' => $this->lookup($context, Grade::class, $r['grade_code'] ?? null, 'Grade code', $errors),
            'department_id' => $this->lookup($context, Department::class, $r['department_code'] ?? null, 'Department code', $errors),
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
                ['Level code', 'No', 'Code of its level (People setup → Levels).', 'L2'],
                ['Grade code', 'No', 'Code of its grade (People setup → Grades).', 'G3'],
                ['Department code', 'No', 'Code of its department (Organisation → Departments).', 'ENG'],
                ['Job family code', 'No', 'Code of its job family (People setup → Job families).', 'TECH'],
                ['Minimum experience (years)', 'No', 'Years, 0 to 99 (halves allowed).', '2'],
                ['Maximum experience (years)', 'No', 'Years, 0 to 99, not below the minimum.', '5'],
                ['Wage skill category', 'No', 'For minimum-wage checks: '.implode(', ', array_keys((array) config('peopleos.compliance.wage_skill_categories', []))).'.', 'skilled'],
                ['Hazardous work', 'No', 'yes or no.', 'no'],
                ['Description', 'No', 'A short description.', 'Builds and maintains product features'],
                ['Status', 'No', 'active or inactive. Blank for a new one is active.', 'active'],
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
