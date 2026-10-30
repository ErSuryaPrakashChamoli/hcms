<?php

namespace App\Domain\Organisation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Concerns\HasCustomFields;
use App\Domain\Identity\Concerns\ScopedByOrganisation;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Services\DepartmentHierarchy;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[UseFactory(DepartmentFactory::class)]
#[Fillable(['tenant_id', 'company_id', 'parent_department_id', 'name', 'code', 'cost_centre_id', 'description', 'status', 'effective_from', 'effective_to', 'metadata'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use ScopedByOrganisation;

    public string $accessScopeDimension = 'department';

    use Auditable, BelongsToTenant, HasCustomFields, HasEffectiveDates, HasFactory;

    protected static function booted(): void
    {
        // HROne setup parity CA-2: sub-departments. No cycles; the organisation tree follows a new parent.
        static::saving(function (Department $department): void {
            if ($department->isDirty('parent_department_id')) {
                $parent = $department->getAttributes()['parent_department_id'] ?? null;
                app(DepartmentHierarchy::class)->assertParent($department, $parent === null ? null : (int) $parent);
            }
        });
        static::saved(function (Department $department): void {
            if ($department->wasChanged('parent_department_id') || ($department->wasRecentlyCreated && filled($department->getAttributes()['parent_department_id'] ?? null))) {
                app(DepartmentHierarchy::class)->syncNode($department);
            }
        });
    }

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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** HROne setup parity CA-2: the department this one is a sub-department of. */
    public function parentDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_department_id');
    }

    public function subDepartments(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_department_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function organisationNode(): MorphOne
    {
        return $this->morphOne(OrganisationNode::class, 'nodeable');
    }
}
