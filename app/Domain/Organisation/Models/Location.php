<?php

namespace App\Domain\Organisation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Concerns\HasCustomFields;
use App\Domain\Identity\Concerns\ScopedByOrganisation;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Enums\LocationType;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[UseFactory(LocationFactory::class)]
#[Fillable(['tenant_id', 'company_id', 'name', 'code', 'type', 'address_line_1', 'address_line_2', 'city', 'state_code', 'postal_code', 'country_code', 'timezone', 'status', 'effective_from', 'effective_to', 'metadata'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use ScopedByOrganisation;

    public string $accessScopeDimension = 'location';

    use Auditable, BelongsToTenant, HasCustomFields, HasEffectiveDates, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ActiveStatus::class,
            'metadata' => 'array',
            'type' => LocationType::class,
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

    public function organisationNode(): MorphOne
    {
        return $this->morphOne(OrganisationNode::class, 'nodeable');
    }
}
