<?php

namespace App\Domain\Configuration\Models;

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One company's own value for one field of a shared configuration record, from a date (entity-specific override,
 * docs/architecture/configuration-applicability.md). Written only through ConfigurationOverrides. A newer override of the
 * same field ends or supersedes the older one; ending an override restores the shared value. Rows are never deleted and,
 * once written, only their end (effective_to, status, ended_by, ended_at) may change.
 */
#[Fillable(['tenant_id', 'ulid', 'configuration_type', 'configuration_id', 'company_id', 'field', 'value', 'shared_value', 'effective_from', 'effective_to', 'status', 'version', 'reason', 'created_by', 'ended_by', 'ended_at'])]
class ConfigurationOverride extends Model
{
    use BelongsToTenant;

    public const ACTIVE = 'active';

    /** Stopped from a date: the shared value applies again from then. */
    public const ENDED = 'ended';

    /** Replaced by a newer override starting the same day, before it ever took effect. */
    public const SUPERSEDED = 'superseded';

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            if (array_diff(array_keys($row->getDirty()), ['effective_to', 'status', 'ended_by', 'ended_at', 'updated_at']) !== []) {
                throw new ConfigurationException('An override is kept as it was made: end it and make a new one instead.');
            }
        });
        static::deleting(fn () => throw new ConfigurationException('Overrides are kept for history: end the override instead of deleting it.'));
    }

    protected function casts(): array
    {
        return ['value' => 'json', 'shared_value' => 'json', 'effective_from' => 'date', 'effective_to' => 'date', 'ended_at' => 'datetime',
            'company_id' => 'integer', 'configuration_id' => 'integer', 'version' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeFor(Builder $query, string $type, int $id): Builder
    {
        return $query->where('configuration_type', $type)->where('configuration_id', $id);
    }

    /** Overrides in force on a date (Y-m-d): active or ended later, started on or before it. */
    public function scopeOn(Builder $query, string $date): Builder
    {
        return $query->whereIn('status', [self::ACTIVE, self::ENDED])
            ->where('effective_from', '<=', $date.' 23:59:59')
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }
}
