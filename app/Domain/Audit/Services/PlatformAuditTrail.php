<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * SaaS.8 (WS1 G-PLAT-1 / WS8): the platform chain for platform operators: what operators did (provisioning, tenant
 * edits, suspensions, tenant entry, plans, subscriptions, billing, configuration) with who, when, why and the before and
 * after values. Read-only, platform chain only (tenant_id null): a tenant's own chain is never listed here.
 *
 * Filters run on indexed columns (tenant_id + occurred_at range, action); the subject tenant is the id operators'
 * events carry in their metadata.
 */
final class PlatformAuditTrail
{
    /** @return Collection<int, AuditEvent> */
    public function events(?string $action = null, ?int $subjectTenantId = null, ?string $from = null, ?string $to = null, int $limit = 50): Collection
    {
        return AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')
            ->when(filled($action), fn ($q) => $q->where('action', $action))
            ->when($subjectTenantId !== null, fn ($q) => $q->where('metadata->subject_tenant_id', $subjectTenantId))
            ->when(filled($from), fn ($q) => $q->where('occurred_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when(filled($to), fn ($q) => $q->where('occurred_at', '<', Carbon::parse($to)->addDay()->startOfDay()))
            ->with('fieldChanges')
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit(max(1, min($limit, 500)))
            ->get();
    }

    /** One platform-chain event (never a tenant chain's). */
    public function find(string $id): ?AuditEvent
    {
        return AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->with('fieldChanges')->find($id);
    }

    /** @return array<string, string> action => label */
    public function actions(): array
    {
        return collect(AuditAction::cases())->mapWithKeys(fn (AuditAction $a) => [$a->value => $a->label()])->sort()->all();
    }
}
