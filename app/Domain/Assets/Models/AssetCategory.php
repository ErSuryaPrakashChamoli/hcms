<?php

namespace App\Domain\Assets\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\Role;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An asset type (HROne "Asset type"), e.g. Laptop. HROne setup parity AS-A: the role that owns the type (told when an
 * employee leaving holds one; asset requests go to it) and the days it has to fulfil a request.
 */
#[Fillable(['tenant_id', 'name', 'code', 'requires_serial', 'is_it_asset', 'default_life_months', 'owner_role_id', 'fulfilment_days', 'status'])]
class AssetCategory extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(fn (self $c) => $c->code = strtoupper(trim((string) $c->code)));
    }

    protected function casts(): array
    {
        return ['requires_serial' => 'boolean', 'is_it_asset' => 'boolean', 'default_life_months' => 'integer', 'fulfilment_days' => 'integer', 'status' => ActiveStatus::class];
    }

    public function auditModule(): string
    {
        return 'assets';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function models(): HasMany
    {
        return $this->hasMany(AssetModel::class);
    }

    public function subtypes(): HasMany
    {
        return $this->hasMany(AssetSubtype::class);
    }

    public function ownerRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'owner_role_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
