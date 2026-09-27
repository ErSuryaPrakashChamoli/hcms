<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'salary_structure_id', 'salary_component_id', 'formula_override', 'sort_order'])]
class SalaryStructureComponent extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return 'Structure component #'.$this->salary_component_id;
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
