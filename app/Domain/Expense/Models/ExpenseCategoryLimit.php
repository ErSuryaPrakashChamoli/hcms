<?php

namespace App\Domain\Expense\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One limit of an expense category: for employees with one value of the category's limit dimension (null = every other
 * employee) in one location group (null = every other place), an amount, a percentage of CTC or a distance, per period.
 * One row per combination (match_key). ExpenseLimits picks the most specific row.
 */
#[Fillable(['tenant_id', 'expense_category_id', 'dimension_value_id', 'expense_location_group_id', 'amount', 'percent', 'km_limit', 'period'])]
class ExpenseCategoryLimit extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['period' => 'per_claim'];

    protected static function booted(): void
    {
        static::saving(function (self $limit): void {
            $limit->match_key = self::matchKey($limit->dimension_value_id, $limit->expense_location_group_id);
        });
    }

    public static function matchKey(mixed $dimensionValueId, mixed $locationGroupId): string
    {
        return (filled($dimensionValueId) ? (int) $dimensionValueId : '').'|'.(filled($locationGroupId) ? (int) $locationGroupId : '');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'percent' => 'decimal:2', 'km_limit' => 'decimal:2', 'dimension_value_id' => 'integer', 'expense_location_group_id' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'expense';
    }

    public function auditLabel(): string
    {
        // Ids only: limits are read in collections, where strict mode refuses lazy relations.
        return "Limit {$this->getAttributes()['match_key']} of expense category #{$this->getAttributes()['expense_category_id']}";
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function locationGroup(): BelongsTo
    {
        return $this->belongsTo(ExpenseLocationGroup::class, 'expense_location_group_id');
    }
}
