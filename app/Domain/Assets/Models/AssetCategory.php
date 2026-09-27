<?php

namespace App\Domain\Assets\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'name', 'code', 'requires_serial', 'is_it_asset', 'default_life_months', 'status'])]
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
        return ['requires_serial' => 'boolean', 'is_it_asset' => 'boolean', 'default_life_months' => 'integer', 'status' => ActiveStatus::class];
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

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
