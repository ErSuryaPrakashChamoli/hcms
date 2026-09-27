<?php

namespace App\Domain\Organisation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Concerns\HasCustomFields;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A legal entity / company within a tenant. Group -> Company is the first level of the
 * organisation tree; locations, departments etc. hang off this in Phase 2.
 */
#[UseFactory(CompanyFactory::class)]
#[Fillable(['tenant_id', 'name', 'code', 'legal_name', 'status', 'country_code', 'timezone', 'currency', 'effective_from', 'effective_to', 'metadata'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use Auditable, BelongsToTenant, HasCustomFields, HasEffectiveDates, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ActiveStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'metadata' => 'array',
        ];
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function organisationNode(): MorphOne
    {
        return $this->morphOne(OrganisationNode::class, 'nodeable');
    }
}
