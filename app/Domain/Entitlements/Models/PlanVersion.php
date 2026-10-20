<?php

namespace App\Domain\Entitlements\Models;

use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Entitlements\Enums\PlanState;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * SaaS.4: the commercial terms of a plan: draft → published → retired (VersionStatus, as for policy, form and
 * workflow versions). A published version is immutable: its entitlements and sale start never change. Only its
 * sale window may end (a later version supersedes it) and it may be retired. Tenants are assigned to a published
 * version and keep it however the catalogue evolves (grandfathering, ADR-0021).
 */
#[Fillable(['plan_id', 'version', 'status', 'effective_from', 'effective_to', 'change_note', 'created_by', 'published_by', 'published_at', 'retired_by', 'retired_at'])]
class PlanVersion extends Model
{
    /** Columns that may still change once a version is published. */
    private const MUTABLE_AFTER_PUBLISH = ['status', 'effective_to', 'retired_by', 'retired_at', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            $original = $version->getRawOriginal('status');
            if ($original === VersionStatus::Draft->value) {
                return;
            }
            $changed = array_diff(array_keys($version->getDirty()), self::MUTABLE_AFTER_PUBLISH);
            $backwards = $version->isDirty('status') && ! ($original === VersionStatus::Published->value && $version->status === VersionStatus::Retired);
            if ($changed !== [] || $backwards) {
                throw new RuntimeException('A published plan version is immutable: create a new draft version.');
            }
        });
        static::deleting(function (self $version): void {
            throw new RuntimeException('Plan versions are never deleted: tenants and history refer to them.');
        });
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'status' => VersionStatus::class, 'effective_from' => 'date', 'effective_to' => 'date',
            'published_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<PlanEntitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function state(?string $day = null): PlanState
    {
        $day ??= now()->toDateString();

        return match (true) {
            $this->status === VersionStatus::Draft => PlanState::Draft,
            $this->status === VersionStatus::Retired => PlanState::Retired,
            $this->effective_from->toDateString() > $day => PlanState::Scheduled,
            $this->effective_to !== null && $this->effective_to->toDateString() < $day => PlanState::Superseded,
            default => PlanState::Active,
        };
    }

    /** Whether a tenant assignment may start on $day: published (not draft, not retired) and inside the sale window. */
    public function onSaleOn(string $day): bool
    {
        return $this->status === VersionStatus::Published && $this->effective_from->toDateString() <= $day
            && ($this->effective_to === null || $this->effective_to->toDateString() >= $day);
    }

    public function label(): string
    {
        return "{$this->loadMissing('plan')->plan->code} v{$this->version}";
    }
}
