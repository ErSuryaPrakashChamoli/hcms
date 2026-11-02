<?php

namespace App\Domain\Organisation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Data import DI-1: a business region that groups branches for the business (locations.business_region_id). */
#[Fillable(['tenant_id', 'code', 'name', 'description', 'status'])]
class BusinessRegion extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['status' => ActiveStatus::class];
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class, 'business_region_id');
    }
}
