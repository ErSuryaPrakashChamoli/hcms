<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Compliance\Concerns\FrozenWithReturn;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Part L: the Form No. 138 (earlier Form 24Q) content of one statutory return — legal entity (TAN) × FY quarter. */
#[Fillable(['tenant_id', 'statutory_return_id', 'legal_entity_id', 'tan_registration_id', 'financial_year', 'quarter', 'form_code', 'legacy_form_code', 'challans', 'annexure_ii', 'deductee_count', 'totals'])]
class TdsQuarterlyReturn extends Model
{
    use BelongsToTenant, FrozenWithReturn;

    protected function casts(): array
    {
        return ['challans' => 'array', 'annexure_ii' => 'array', 'totals' => 'array'];
    }

    public function statutoryReturn(): BelongsTo
    {
        return $this->belongsTo(StatutoryReturn::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TdsQuarterlyReturnEntry::class);
    }
}
