<?php

namespace App\Domain\Configuration\Models;

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One company a configuration record applies to, from one date to another. Rows are end-dated, never deleted, so the
 * history of which companies a record applied to is kept (only effective_to, ended_by and reason change after insert).
 */
#[Fillable(['tenant_id', 'applicability_id', 'company_id', 'effective_from', 'effective_to', 'source', 'added_by', 'ended_by', 'reason'])]
class ConfigurationApplicabilityCompany extends Model
{
    use BelongsToTenant;

    /** The reach the record had before its companies were first chosen (seeded so history stays true). */
    public const EARLIER = 'earlier';

    public const CHOSEN = 'chosen';

    /** Joined automatically as a company created after "include companies created later" was chosen. */
    public const FUTURE = 'future';

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            if (array_diff(array_keys($row->getDirty()), ['effective_to', 'ended_by', 'reason', 'updated_at']) !== []) {
                throw new ConfigurationException('Applicability history is kept as it was: a company row can only be ended.');
            }
        });
        static::deleting(fn () => throw new ConfigurationException('Applicability history is kept: end the company row instead of deleting it.'));
    }

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'company_id' => 'integer'];
    }

    public function applicability(): BelongsTo
    {
        return $this->belongsTo(ConfigurationApplicability::class, 'applicability_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Rows in force on a date (Y-m-d). */
    public function scopeOn(Builder $query, string $date): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date.' 23:59:59'))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }
}
