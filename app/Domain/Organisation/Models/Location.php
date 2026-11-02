<?php

namespace App\Domain\Organisation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Concerns\HasCustomFields;
use App\Domain\Identity\Concerns\ScopedByOrganisation;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Enums\LocationType;
use App\Domain\Organisation\Services\LocationHierarchy;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[UseFactory(LocationFactory::class)]
#[Fillable(['tenant_id', 'company_id', 'establishment_id', 'parent_location_id', 'region_id', 'business_region_id', 'name', 'code', 'type', 'address_line_1', 'address_line_2', 'city', 'state_code', 'postal_code', 'country_code', 'timezone', 'status', 'effective_from', 'effective_to', 'metadata'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use ScopedByOrganisation;

    public string $accessScopeDimension = 'location';

    use Auditable, BelongsToTenant, HasCustomFields, HasEffectiveDates, HasFactory;

    protected static function booted(): void
    {
        // Data import DI-1: a sub branch sits inside a branch of the same company, never inside itself.
        static::saving(function (Location $location): void {
            app(LocationHierarchy::class)->assertValid($location);
        });
    }

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

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    /** Data import DI-1: the branch this location is a sub branch of. */
    public function parentLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'parent_location_id');
    }

    public function subLocations(): HasMany
    {
        return $this->hasMany(Location::class, 'parent_location_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    public function businessRegion(): BelongsTo
    {
        return $this->belongsTo(BusinessRegion::class, 'business_region_id');
    }
}
