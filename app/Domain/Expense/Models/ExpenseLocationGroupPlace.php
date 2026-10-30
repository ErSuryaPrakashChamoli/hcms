<?php

namespace App\Domain\Expense\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A state (city null) or a city of a state in an expense location group. A place belongs to one group. */
#[Fillable(['tenant_id', 'expense_location_group_id', 'country_code', 'state_code', 'city'])]
class ExpenseLocationGroupPlace extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['country_code' => 'IN'];

    protected static function booted(): void
    {
        static::saving(function (self $place): void {
            $place->country_code = strtoupper(trim((string) $place->country_code)) ?: 'IN';
            $place->state_code = $place->country_code === 'IN' ? strtoupper(trim((string) $place->state_code)) : trim((string) $place->state_code);
            $city = trim((string) $place->city);
            $place->city = $city === '' ? null : $city;
            $place->city_key = self::key($city);
        });
    }

    /** The normalised form a city is matched on (lower case, single spaces). */
    public static function key(?string $city): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $city)) ?? '');
    }

    public function auditModule(): string
    {
        return 'expense';
    }

    public function auditLabel(): string
    {
        return trim("{$this->state_code} ".($this->city ?? '(whole state)'));
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ExpenseLocationGroup::class, 'expense_location_group_id');
    }
}
