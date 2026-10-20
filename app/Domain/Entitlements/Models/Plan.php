<?php

namespace App\Domain\Entitlements\Models;

use App\Domain\Entitlements\Enums\PlanState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SaaS.4: a commercial plan's stable identity in Markedge's platform catalogue (no tenant). The code never changes;
 * the name and description may. What the plan includes lives in its versions; tenants are assigned to a version.
 * Written only through PlanCatalog (platform operators, reasoned, audited on the platform chain).
 */
#[Fillable(['code', 'name', 'description', 'created_by', 'updated_by'])]
class Plan extends Model
{
    public const CODE_PATTERN = '/^[a-z][a-z0-9_-]{1,63}$/';

    protected static function booted(): void
    {
        static::updating(function (self $plan): void {
            if ($plan->isDirty('code')) {
                throw new \RuntimeException('A plan code never changes.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Plans are never deleted: tenants and history refer to them.'));
    }

    /** @return HasMany<PlanVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class)->orderBy('version');
    }

    /** The plan's standing on $day: draft until a version is published, then the best state among its versions. */
    public function state(?string $day = null): PlanState
    {
        $states = $this->versions->map(fn (PlanVersion $v) => $v->state($day));

        foreach ([PlanState::Active, PlanState::Scheduled] as $state) {
            if ($states->contains($state)) {
                return $state;
            }
        }

        return $states->contains(fn (PlanState $s) => $s !== PlanState::Draft) ? PlanState::Retired : PlanState::Draft;
    }
}
