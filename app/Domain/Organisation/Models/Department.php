<?php

namespace App\Domain\Organisation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Concerns\HasCustomFields;
use App\Domain\Identity\Concerns\ScopedByOrganisation;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[UseFactory(DepartmentFactory::class)]
#[Fillable(['tenant_id', 'company_id', 'name', 'code', 'cost_centre_id', 'description', 'status', 'effective_from', 'effective_to', 'metadata'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use ScopedByOrganisation;

    public string $accessScopeDimension = 'department';

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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
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
