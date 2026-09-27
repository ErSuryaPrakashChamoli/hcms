<?php

namespace App\Domain\Assets\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'asset_id', 'method', 'disposed_on', 'value', 'reference', 'note', 'approved_by'])]
class AssetDisposal extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['disposed_on' => 'date', 'value' => 'decimal:2'];
    }

    public function auditModule(): string
    {
        return 'assets';
    }

    public function auditLabel(): string
    {
        return 'Disposal '.$this->disposed_on?->toDateString();
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
