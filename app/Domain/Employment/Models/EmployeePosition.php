<?php

namespace App\Domain\Employment\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Concerns\ScopedByEmployee;
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
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Effective-dated organisational assignment. See AssignPositionAction for how rows are opened/closed. */
#[Fillable(['tenant_id', 'employee_id', 'company_id', 'location_id', 'business_unit_id', 'division_id', 'department_id', 'team_id', 'designation_id', 'level_id', 'grade_id', 'employment_type_id', 'employee_category_id', 'work_mode_id', 'cost_centre_id', 'change_type', 'effective_from', 'effective_to', 'reason'])]
class EmployeePosition extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates;
    use ScopedByEmployee;

    /** Dimension => [relation, human label]. Used for diffs on the timeline. */
    public const DIMENSIONS = [
        'company_id' => ['company', 'Company'],
        'location_id' => ['location', 'Location'],
        'business_unit_id' => ['businessUnit', 'Business unit'],
        'division_id' => ['division', 'Division'],
        'department_id' => ['department', 'Department'],
        'team_id' => ['team', 'Team'],
        'designation_id' => ['designation', 'Designation'],
        'level_id' => ['level', 'Level'],
        'grade_id' => ['grade', 'Grade'],
        'employment_type_id' => ['employmentType', 'Employment type'],
        'employee_category_id' => ['employeeCategory', 'Employee category'],
        'work_mode_id' => ['workMode', 'Work mode'],
        'cost_centre_id' => ['costCentre', 'Cost centre'],
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function auditLabel(): string
    {
        return "Position from {$this->effective_from?->toDateString()}";
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class);
    }

    public function employeeCategory(): BelongsTo
    {
        return $this->belongsTo(EmployeeCategory::class);
    }

    public function workMode(): BelongsTo
    {
        return $this->belongsTo(WorkMode::class);
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class);
    }

    /** @return list<string> relation names */
    public static function dimensionRelations(): array
    {
        return array_column(self::DIMENSIONS, 0);
    }
}
