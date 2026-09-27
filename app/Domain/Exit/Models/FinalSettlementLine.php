<?php

namespace App\Domain\Exit\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'final_settlement_id', 'type', 'code', 'name', 'amount', 'basis', 'source', 'sort_order'])]
class FinalSettlementLine extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'basis' => 'array', 'sort_order' => 'integer'];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(FinalSettlement::class, 'final_settlement_id');
    }
}
