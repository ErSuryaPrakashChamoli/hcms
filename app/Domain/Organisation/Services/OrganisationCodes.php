<?php

namespace App\Domain\Organisation\Services;

use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\CostCentre;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Division;
use App\Domain\Organisation\Models\EmployeeCategory;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\Team;
use App\Domain\Organisation\Models\WorkMode;
use InvalidArgumentException;

/**
 * Resolves organisation codes (the stable, tenant-owned identifiers integrations and imports use)
 * into position dimension ids. Codes are matched case-insensitively within the bound tenant.
 */
final class OrganisationCodes
{
    /** code field => [position column, model] */
    public const DIMENSIONS = [
        'company_code' => ['company_id', Company::class], 'location_code' => ['location_id', Location::class], 'business_unit_code' => ['business_unit_id', BusinessUnit::class],
        'division_code' => ['division_id', Division::class], 'department_code' => ['department_id', Department::class], 'team_code' => ['team_id', Team::class],
        'designation_code' => ['designation_id', Designation::class], 'level_code' => ['level_id', Level::class], 'grade_code' => ['grade_id', Grade::class],
        'employment_type_code' => ['employment_type_id', EmploymentType::class], 'employee_category_code' => ['employee_category_id', EmployeeCategory::class],
        'work_mode_code' => ['work_mode_id', WorkMode::class], 'cost_centre_code' => ['cost_centre_id', CostCentre::class],
    ];

    /** API resource types => model */
    public const TYPES = [
        'companies' => Company::class, 'locations' => Location::class, 'business-units' => BusinessUnit::class, 'divisions' => Division::class,
        'departments' => Department::class, 'teams' => Team::class, 'designations' => Designation::class, 'levels' => Level::class, 'grades' => Grade::class,
        'employment-types' => EmploymentType::class, 'employee-categories' => EmployeeCategory::class, 'work-modes' => WorkMode::class, 'cost-centres' => CostCentre::class,
    ];

    /**
     * @param  array<string, mixed>  $codes  e.g. ['company_code' => 'ALPHA', 'department_code' => 'ENG']
     * @return array<string, int> position dimension ids
     */
    public function resolvePosition(array $codes): array
    {
        $position = [];
        foreach ($codes as $field => $code) {
            if (! isset(self::DIMENSIONS[$field]) || $code === null || $code === '') {
                continue;
            }
            [$column, $model] = self::DIMENSIONS[$field];
            $id = $model::query()->whereRaw('lower(code) = ?', [mb_strtolower((string) $code)])->value('id');
            if ($id === null) {
                throw new InvalidArgumentException(str_replace('_', ' ', ucfirst($field))." '{$code}' does not exist.");
            }
            $position[$column] = (int) $id;
        }

        return $position;
    }
}
