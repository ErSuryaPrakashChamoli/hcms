<?php

namespace App\Domain\Expense\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Expense\Support\MasterCode;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne setup parity EX-A (Expense → Location group): places grouped for expense limits, e.g. Tier 1, Tier 2 and Tier 3
 * cities. A group covers whole states and single cities (ExpenseLocationGroups resolves a place to its group); a place
 * in no group takes the limits set for "every other place".
 */
#[Fillable(['tenant_id', 'name', 'code', 'sort_order', 'status'])]
class ExpenseLocationGroup extends Model
{
    use Auditable, BelongsToTenant, MasterCode;

    protected $attributes = ['status' => 'active', 'sort_order' => 0];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'status' => ActiveStatus::class];
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function places(): HasMany
    {
        return $this->hasMany(ExpenseLocationGroupPlace::class)->orderBy('state_code')->orderBy('city_key');
    }
}
