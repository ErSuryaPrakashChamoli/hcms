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
 * HROne setup parity EX-A (Expense → Expense group): a heading expense categories are filed under (Travel, Meals, Local
 * conveyance …). Workflows can be split by group (expense.workflow.split_by, EX-C).
 */
#[Fillable(['tenant_id', 'name', 'code', 'sort_order', 'status'])]
class ExpenseGroup extends Model
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

    public function categories(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class);
    }
}
