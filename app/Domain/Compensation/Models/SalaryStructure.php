<?php

namespace App\Domain\Compensation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An ordered set of payroll components an employee's pay is built from (§30). Phase 11: owned by
 * Compensation (structures, ranges, assignments); the components themselves stay in Payroll's
 * component catalogue, which carries their statutory treatment.
 */
#[Fillable(['tenant_id', 'name', 'code', 'description', 'status'])]
class SalaryStructure extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        static::saving(fn (self $s) => $s->code = strtoupper(trim((string) $s->code)));
    }

    protected function casts(): array
    {
        return ['status' => ActiveStatus::class];
    }

    public function auditModule(): string
    {
        return 'compensation';
    }

    /** Audit rows written before Phase 11 name the class's previous (Payroll) location. */
    public function auditEntityAliases(): array
    {
        return ['App\\Domain\\Payroll\\Models\\SalaryStructure'];
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalaryStructureComponent::class)->orderBy('sort_order');
    }

    public function components(): BelongsToMany
    {
        return $this->belongsToMany(SalaryComponent::class, 'salary_structure_components')->withPivot(['formula_override', 'sort_order'])->orderByPivot('sort_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeSalaryAssignment::class);
    }
}
