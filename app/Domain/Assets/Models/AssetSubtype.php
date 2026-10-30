<?php

namespace App\Domain\Assets\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne setup parity AS-A (Asset → Asset sub type): the level between an asset type (category) and a product (model),
 * e.g. Laptop → Premium series. It decides whether two assets of the sub type may share a serial number and whether
 * the employee must acknowledge receiving one (a task on My tasks, with reminders).
 */
#[Fillable(['tenant_id', 'asset_category_id', 'name', 'code', 'allow_duplicate_serial', 'acknowledgement_required', 'sort_order', 'status'])]
class AssetSubtype extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active', 'allow_duplicate_serial' => true, 'acknowledgement_required' => false, 'sort_order' => 0];

    protected static function booted(): void
    {
        static::saving(function (self $subtype): void {
            $subtype->code = strtoupper(trim((string) $subtype->code));
        });
    }

    protected function casts(): array
    {
        return ['allow_duplicate_serial' => 'boolean', 'acknowledgement_required' => 'boolean', 'sort_order' => 'integer', 'status' => ActiveStatus::class];
    }

    public function auditModule(): string
    {
        return 'assets';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function models(): HasMany
    {
        return $this->hasMany(AssetModel::class, 'asset_subtype_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'asset_subtype_id');
    }
}
