<?php

namespace App\Domain\Assets\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A make/model within a category, e.g. Dell Latitude 5540. */
#[Fillable(['tenant_id', 'asset_category_id', 'manufacturer', 'name', 'specifications', 'status'])]
class AssetModel extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['specifications' => 'array', 'status' => ActiveStatus::class];
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
}
