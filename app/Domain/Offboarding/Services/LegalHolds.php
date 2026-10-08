<?php

namespace App\Domain\Offboarding\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Identity\Models\User;
use App\Domain\Offboarding\Enums\LegalHoldStatus;
use App\Domain\Offboarding\Models\LegalHold;
use App\Domain\Offboarding\Models\TenantOffboarding;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Offboarding\Support\OffboardingAudit;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Support\LifecycleRefused;
use App\Domain\Platform\Support\PlatformOperator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * SaaS.9: legal holds. While a hold is active (or its release is only requested), nothing it covers is deleted: not by a
 * tenant deletion, not by the retention purge of operational logs, not by the warehouse snapshot expiry.
 *
 * - Placing a hold protects at once: one platform operator with a reason (a hold never destroys anything).
 * - Releasing it allows destruction again: requested by one operator, approved by another.
 * - The race with a deletion: placing a hold and every deletion batch take the same lock (the tenant's open offboarding
 *   case, lockFor()). A hold that commits first stops the next batch (the worker re-checks under that lock immediately
 *   before deleting); a batch that commits first was already allowed. Nothing a hold covers is deleted after it exists.
 */
final class LegalHolds
{
    public function __construct(private readonly OffboardingAudit $audit, private readonly TenantContext $tenants) {}

    /** @param  list<string>|null  $categories  null or [] = the whole tenant */
    public function place(Tenant $tenant, ?array $categories, string $reason, ?string $matterReference, ?string $startsOn, ?User $actor): LegalHold
    {
        $actor = PlatformOperator::assert($actor, 'place a legal hold');
        $reason = PlatformOperator::reason($reason);
        $categories = array_values(array_unique(array_filter((array) $categories)));
        foreach ($categories as $category) {
            if (! array_key_exists($category, DataInventory::CATEGORIES)) {
                throw new InvalidArgumentException("Unknown data category [{$category}].");
            }
        }

        $hold = DB::transaction(function () use ($tenant, $categories, $reason, $matterReference, $startsOn, $actor) {
            self::lockFor($tenant->getKey());

            return $this->tenants->runAs($tenant, fn () => LegalHold::query()->create([
                'tenant_id' => $tenant->getKey(), 'reference' => (string) Str::ulid(), 'status' => LegalHoldStatus::Active,
                'scope' => $categories === [] ? LegalHold::SCOPE_TENANT : LegalHold::SCOPE_CATEGORIES, 'categories' => $categories === [] ? null : $categories,
                'reason' => $reason, 'matter_reference' => $matterReference, 'starts_on' => Carbon::parse($startsOn ?? today())->toDateString(),
                'placed_by' => $actor?->getKey(), 'placed_at' => now(),
            ]));
        });
        $this->audit->record(AuditAction::LegalHoldPlaced, $tenant, $hold, $reason, $actor,
            ['hold' => $hold->reference, 'scope' => $hold->scope, 'categories' => $hold->categories, 'matter_reference' => $matterReference]);

        return $hold;
    }

    public function requestRelease(LegalHold $hold, ?User $actor, string $reason): LegalHold
    {
        $actor = PlatformOperator::assert($actor, 'request the release of a legal hold');
        $reason = PlatformOperator::reason($reason);
        $changed = $this->tenants->bypass(fn () => LegalHold::query()->whereKey($hold->getKey())->where('status', LegalHoldStatus::Active->value)
            ->update(['status' => LegalHoldStatus::ReleaseRequested->value, 'release_requested_by' => $actor?->getKey(), 'release_requested_at' => now(),
                'release_reason' => $reason, 'updated_at' => now()]));
        if ($changed !== 1) {
            throw new LifecycleRefused('This hold is not active.');
        }
        $hold = $this->fresh($hold);
        $this->audit->record(AuditAction::LegalHoldReleaseRequested, $this->tenantOf($hold), $hold, $reason, $actor, ['hold' => $hold->reference]);

        return $hold;
    }

    /** A second operator releases the hold (never the one who asked for the release). */
    public function approveRelease(LegalHold $hold, ?User $actor, string $reason): LegalHold
    {
        $actor = PlatformOperator::assert($actor, 'release a legal hold');
        $reason = PlatformOperator::reason($reason);
        if ($actor !== null && (int) $hold->release_requested_by === (int) $actor->getKey()) {
            throw new LifecycleRefused('A legal hold is released by an operator other than the one who asked for its release.');
        }
        $changed = $this->tenants->bypass(fn () => LegalHold::query()->whereKey($hold->getKey())->where('status', LegalHoldStatus::ReleaseRequested->value)
            ->where('release_requested_by', '!=', $actor?->getKey() ?? 0)
            ->update(['status' => LegalHoldStatus::Released->value, 'released_by' => $actor?->getKey(), 'released_at' => now(), 'updated_at' => now()]));
        if ($changed !== 1) {
            throw new LifecycleRefused('This hold has no pending release request.');
        }
        $hold = $this->fresh($hold);
        $this->audit->record(AuditAction::LegalHoldReleased, $this->tenantOf($hold), $hold, $reason, $actor, ['hold' => $hold->reference, 'release_requested_by' => $hold->release_requested_by]);

        return $hold;
    }

    /** A second operator declines the release: the hold stays active. */
    public function rejectRelease(LegalHold $hold, ?User $actor, string $reason): LegalHold
    {
        $actor = PlatformOperator::assert($actor, 'decline the release of a legal hold');
        $reason = PlatformOperator::reason($reason);
        $changed = $this->tenants->bypass(fn () => LegalHold::query()->whereKey($hold->getKey())->where('status', LegalHoldStatus::ReleaseRequested->value)
            ->update(['status' => LegalHoldStatus::Active->value, 'release_requested_by' => null, 'release_requested_at' => null, 'release_reason' => null, 'updated_at' => now()]));
        if ($changed !== 1) {
            throw new LifecycleRefused('This hold has no pending release request.');
        }
        $hold = $this->fresh($hold);
        $this->audit->record(AuditAction::LegalHoldReleaseDeclined, $this->tenantOf($hold), $hold, $reason, $actor, ['hold' => $hold->reference]);

        return $hold;
    }

    /** @return list<LegalHold> holds that still hold (active or release requested) */
    public function holding(int $tenantId): array
    {
        return $this->tenants->bypass(fn () => LegalHold::query()->where('tenant_id', $tenantId)
            ->whereIn('status', [LegalHoldStatus::Active->value, LegalHoldStatus::ReleaseRequested->value])->orderBy('id')->get()->all());
    }

    /** @return list<string> references of the holds covering one category of a tenant */
    public function covering(int $tenantId, string $category): array
    {
        return array_values(array_map(fn (LegalHold $h) => $h->reference, array_filter($this->holding($tenantId), fn (LegalHold $h) => $h->covers($category))));
    }

    /**
     * The tenant's exit lock: its open offboarding case row, locked for the transaction. Placing a hold and each deletion
     * batch take it, so they never interleave. Without an open case no deletion can run, and the (gap) lock still
     * serialises with a case being opened.
     */
    public static function lockFor(int $tenantId): ?TenantOffboarding
    {
        return app(TenantContext::class)->bypass(fn () => TenantOffboarding::query()->where('open_key', $tenantId)->lockForUpdate()->first());
    }

    private function fresh(LegalHold $hold): LegalHold
    {
        return $this->tenants->bypass(fn () => LegalHold::query()->findOrFail($hold->getKey()));
    }

    private function tenantOf(LegalHold $hold): Tenant
    {
        return Tenant::query()->findOrFail($hold->tenant_id);
    }
}
