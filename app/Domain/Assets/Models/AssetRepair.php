<?php

namespace App\Domain\Assets\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'asset_id', 'vendor', 'issue', 'sent_on', 'returned_on', 'cost', 'resolution', 'status'])]
class AssetRepair extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['sent_on' => 'date', 'returned_on' => 'date', 'cost' => 'decimal:2'];
    }

    public function auditModule(): string
    {
        return 'assets';
    }

    public function auditLabel(): string
    {
        return 'Repair from '.$this->sent_on?->toDateString();
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
