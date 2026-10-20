<?php

namespace App\Domain\Compensation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component line of a structure version: a payroll component, its order, an optional formula
 * override and its compensation attributes (pay nature fixed / variable, frequency). Lines belong to a
 * version and change only while that version is a draft (Phase 11).
 */
#[Fillable(['tenant_id', 'salary_structure_id', 'salary_structure_version_id', 'salary_component_id', 'formula_override', 'pay_nature', 'frequency', 'sort_order'])]
class SalaryStructureComponent extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['pay_nature' => 'fixed', 'frequency' => 'monthly'];

    protected static function booted(): void
    {
        $guard = function (self $line): void {
            $versionId = $line->salary_structure_version_id ?? $line->getRawOriginal('salary_structure_version_id');
            $status = $versionId ? SalaryStructureVersion::query()->whereKey($versionId)->value('status') : null;
            if ($status !== 'draft') {
                throw new CompensationRuleViolation('Structure components change only in a draft version; approved versions are immutable.');
            }
        };
        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'compensation';
    }

    /** Audit rows written before Phase 11 name the class's previous (Payroll) location. */
    public function auditEntityAliases(): array
    {
        return ['App\\Domain\\Payroll\\Models\\SalaryStructureComponent'];
    }

    public function auditLabel(): string
    {
        return 'Structure version #'.$this->salary_structure_version_id.' component #'.$this->salary_component_id;
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SalaryStructureVersion::class, 'salary_structure_version_id');
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }
}
