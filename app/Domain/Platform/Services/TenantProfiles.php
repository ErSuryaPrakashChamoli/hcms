<?php

namespace App\Domain\Platform\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Support\PlatformOperator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * SaaS.8: an operator's change to a tenant's record (name, slug, tier, region, trial end, locale defaults).
 *
 * The edit page saved the model directly: the change reached the tenant's chain only, with an optional reason, and the
 * page was the only guard. Now a platform operator and a reason are required here, the status is never changed (that is
 * TenantSuspensions), unknown tiers and regions are refused, and the change is recorded on both chains with its before
 * and after values.
 */
final class TenantProfiles
{
    public const EDITABLE = ['name', 'slug', 'tier', 'region', 'trial_ends_at', 'country_code', 'timezone', 'locale', 'currency'];

    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param  array<string, mixed>  $data */
    public function update(Tenant $tenant, array $data, ?User $actor, ?string $reason): Tenant
    {
        $actor = PlatformOperator::assert($actor, "change a tenant's record");
        $reason = PlatformOperator::reason($reason);
        $data = Arr::only($data, self::EDITABLE);
        if (array_key_exists('tier', $data) && ! array_key_exists((string) $data['tier'], config('peopleos.enterprise.tiers', []))) {
            throw new InvalidArgumentException("Unknown tenant tier [{$data['tier']}].");
        }
        if (filled($data['region'] ?? null) && ! array_key_exists((string) $data['region'], config('peopleos.enterprise.regions', []))) {
            throw new InvalidArgumentException("Unknown data region [{$data['region']}].");
        }

        $tenant->fill($data);
        $changes = collect($tenant->getDirty())->map(fn ($after, string $field) => [
            'field' => $field,
            'before' => $this->scalar($tenant->getOriginal($field)),
            'after' => $this->scalar($tenant->getAttribute($field)),
        ])->values()->all();
        if ($changes === []) {
            return $tenant;
        }

        $tenant->withAuditReason($reason)->withAuditAction(AuditAction::TenantProfileChanged)->save();
        $this->audit->record(AuditAction::TenantProfileChanged, 'platform', null, $changes, $reason, entityLabel: "Tenant {$tenant->name}", actor: $actor,
            metadata: ['subject_tenant_id' => $tenant->getKey(), 'subject_tenant' => $tenant->slug], platform: true);

        return $tenant;
    }

    private function scalar(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Carbon => $value->toIso8601String(),
            $value instanceof \BackedEnum => $value->value,
            default => $value,
        };
    }
}
