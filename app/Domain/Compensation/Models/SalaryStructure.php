<?php

namespace App\Domain\Compensation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A compensation structure (§30): the identity (code, name) of an ordered set of payroll components an
 * employee's pay is built from. Phase 11: owned by Compensation; its composition is a series of
 * effective-dated versions (SalaryStructureVersion); the components themselves stay in Payroll's
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

    /** Phase 11: the structure's composition over time (effective-dated, immutable once approved). */
    public function versions(): HasMany
    {
        return $this->hasMany(SalaryStructureVersion::class)->orderBy('version');
    }

    /** The approved version in force on a date (null when none). */
    public function versionOn(CarbonInterface|string|null $date = null): ?SalaryStructureVersion
    {
        return $this->versions()->getQuery()->whereIn('status', SalaryStructureVersion::APPROVED)->effectiveOn($date)->reorder('effective_from', 'desc')->first();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeSalaryAssignment::class);
    }
}
