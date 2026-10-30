<?php

namespace App\Domain\Assets\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A make/model within a category, e.g. Dell Latitude 5540 (HROne "Product"). HROne setup parity AS-A: its sub type, a
 * description, its own useful life (overrides the category's for book value), how it is deployed, what a licence
 * covers, and documents on the private documents disk (downloaded through signed links).
 */
#[Fillable(['tenant_id', 'asset_category_id', 'asset_subtype_id', 'manufacturer', 'name', 'description', 'life_months', 'deployment_type', 'licence_basis', 'specifications', 'documents', 'status'])]
class AssetModel extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            $subtypeId = $model->getAttributes()['asset_subtype_id'] ?? null;
            if ($subtypeId !== null && (int) AssetSubtype::query()->whereKey($subtypeId)->value('asset_category_id') !== (int) $model->asset_category_id) {
                throw new RuntimeException('The sub type belongs to another asset type.');
            }
            foreach (['deployment_type' => 'peopleos.assets.deployment_types', 'licence_basis' => 'peopleos.assets.licence_bases'] as $field => $list) {
                $value = $model->getAttributes()[$field] ?? null;
                if (filled($value) && ! array_key_exists((string) $value, (array) config($list, []))) {
                    throw new RuntimeException("Unknown {$field} [{$value}].");
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['specifications' => 'array', 'documents' => 'array', 'life_months' => 'integer', 'status' => ActiveStatus::class];
    }

    public function auditModule(): string
    {
        return 'assets';
    }

    public function auditLabel(): string
    {
        return trim("{$this->manufacturer} {$this->name}");
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function subtype(): BelongsTo
    {
        return $this->belongsTo(AssetSubtype::class, 'asset_subtype_id');
    }
}
