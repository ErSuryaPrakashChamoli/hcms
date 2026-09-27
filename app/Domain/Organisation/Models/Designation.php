<?php

namespace App\Domain\Organisation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Concerns\HasCustomFields;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\DesignationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[UseFactory(DesignationFactory::class)]
#[Fillable(['tenant_id', 'name', 'code', 'level_id', 'grade_id', 'job_family_id', 'department_id', 'default_reporting_level_id', 'description', 'status', 'effective_from', 'effective_to', 'metadata'])]
class Designation extends Model
{
    /** @use HasFactory<DesignationFactory> */
    use Auditable, BelongsToTenant, HasCustomFields, HasEffectiveDates, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ActiveStatus::class,
            'metadata' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class, 'level_id');
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class, 'grade_id');
    }

    public function jobFamily(): BelongsTo
    {
        return $this->belongsTo(JobFamily::class, 'job_family_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function defaultReportingLevel(): BelongsTo
    {
        return $this->belongsTo(Level::class, 'default_reporting_level_id');
    }

    public function employmentTypes(): BelongsToMany
    {
        return $this->belongsToMany(EmploymentType::class, 'designation_employment_type');
    }
}
