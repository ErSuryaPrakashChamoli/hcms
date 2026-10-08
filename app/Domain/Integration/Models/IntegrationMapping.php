<?php

namespace App\Domain\Integration\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 14 (ADR-0014 addendum): an external system's value for an organisation dimension (company,
 * location, department, grade, …) mapped to the PeopleOS record. External values are opaque strings;
 * when no mapping exists the PeopleOS code is the default mapping.
 */
#[Fillable(['tenant_id', 'integration_system_id', 'dimension', 'external_value', 'peopleos_type', 'peopleos_id', 'status'])]
class IntegrationMapping extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['peopleos_id' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'integration';
    }

    public function auditLabel(): string
    {
        return "{$this->dimension}:{$this->external_value}";
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(IntegrationSystem::class, 'integration_system_id');
    }
}
