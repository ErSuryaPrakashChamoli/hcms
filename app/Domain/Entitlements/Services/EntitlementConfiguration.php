<?php

namespace App\Domain\Entitlements\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\CapabilityType;
use App\Domain\Entitlements\Models\EntitlementOverride;
use App\Domain\Entitlements\Models\TenantEntitlement;
use App\Domain\Entitlements\Models\TenantEntitlementProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SaaS.3: the only way a tenant's commercial entitlements change. Platform operators only (a tenant user, whatever
 * their roles, is refused here, not just hidden from a screen); every change needs a reason.
 *
 * - History is never rewritten. Changes start today or later (business dates, application time zone); a past day
 *   keeps the answer it had. Values and start dates are immutable: a change ends the row it replaces (its last
 *   day becomes the day before) or, if that row has not started, cancels it.
 * - One answer at a time. For each capability at most one active configuration row and one active override cover
 *   any day. set() paints its range over the configuration (splitting a row that continues past it); an override
 *   must not overlap another override (revoke first), so an override is always a deliberate, single exception.
 * - Serialised per tenant on the tenant's profile row (FOR UPDATE), never on the tenants row; a duplicate request
 *   finds its own result and changes nothing (idempotent).
 * - Audited on the tenant's chain and on the platform chain; the cached state is forgotten after commit.
 */
final class EntitlementConfiguration
{
    public function __construct(private readonly TenantContext $tenants, private readonly AuditRecorder $audit) {}

    /** The tenant's commercial configuration starts on $from: from then on, an absent capability is NOT entitled. */
    public function configure(Tenant $tenant, string $from, string $reason, User $actor): TenantEntitlementProfile
    {
        $this->guard($actor, $reason);
        $from = $this->startDay($from);

        return $this->locked($tenant, function (TenantEntitlementProfile $profile) use ($tenant, $from, $reason, $actor) {
            $current = $profile->configured_from?->toDateString();
            if ($profile->state === TenantEntitlementProfile::CONFIGURED && $current === $from) {
                return $profile;
            }
            if ($profile->state === TenantEntitlementProfile::CONFIGURED && $current <= $this->today()) {
                throw new RuntimeException("This tenant's commercial configuration already started on {$current}; it cannot be moved.");
            }
            $profile->forceFill(['state' => TenantEntitlementProfile::CONFIGURED, 'configured_from' => $from, 'version' => $profile->version + 1, 'updated_by' => $actor->id])->save();
            $this->recordAudit(AuditAction::EntitlementConfigured, $tenant, $profile, $reason, $actor, ['configured_from' => $from, 'previous_configured_from' => $current, 'version' => $profile->version]);

            return $profile;
        });
    }

    /**
     * The capability's value from $from (today or later) until $to (inclusive; null = open-ended).
     * Booleans for modules and features; an integer (or null = unlimited) for limits.
     */
    public function set(Tenant $tenant, Capability $capability, bool|int|null $value, string $from, ?string $to, string $reason, User $actor, ?string $reference = null): TenantEntitlement
    {
        $this->guard($actor, $reason);
        [$valueBool, $valueInt] = $this->value($capability, $value);
        [$from, $to] = [$this->startDay($from), $this->endDay($to, $from)];

        return $this->locked($tenant, function (TenantEntitlementProfile $profile) use ($tenant, $capability, $valueBool, $valueInt, $from, $to, $reason, $actor, $reference) {
            $rows = TenantEntitlement::query()->where('capability', $capability->value)->where('status', TenantEntitlement::ACTIVE)->lockForUpdate()->get();
            $same = $rows->first(fn (TenantEntitlement $r) => $r->value_bool === $valueBool && $r->value_int === $valueInt
                && $r->effective_from->toDateString() === $from && $r->effective_to?->toDateString() === $to);
            if ($same !== null) {
                return $same;
            }

            $new = new TenantEntitlement(['capability' => $capability, 'value_bool' => $valueBool, 'value_int' => $valueInt, 'effective_from' => $from,
                'effective_to' => $to, 'status' => TenantEntitlement::ACTIVE, 'reason' => $reason, 'reference' => $reference, 'created_by' => $actor->id]);
            $replaced = $this->paint($rows, $from, $to, $new, $actor, $reason);
            $new->save();
            TenantEntitlement::query()->whereKey($replaced['cancelled'])->update(['superseded_by' => $new->id]);
            $this->bump($profile, $actor);
            $this->recordAudit(AuditAction::EntitlementSet, $tenant, $new, $reason, $actor, ['capability' => $capability->value, 'value' => $valueBool ?? $valueInt,
                'effective_from' => $from, 'effective_to' => $to, 'reference' => $reference, 'version' => $profile->version] + $replaced);

            return $new;
        });
    }

    /** The configuration row's last day becomes $lastDay (yesterday at the earliest); a row not yet started is cancelled. */
    public function end(TenantEntitlement $row, string $lastDay, string $reason, User $actor): TenantEntitlement
    {
        $this->guard($actor, $reason);
        $lastDay = $this->endDay($lastDay, null);
        if ($lastDay < $this->yesterday()) {
            throw new RuntimeException('A configuration can end yesterday at the earliest: past days keep their answer.');
        }
        $tenant = Tenant::query()->findOrFail($row->tenant_id);

        return $this->locked($tenant, function (TenantEntitlementProfile $profile) use ($tenant, $row, $lastDay, $reason, $actor) {
            $row = TenantEntitlement::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            $previous = $row->effective_to?->toDateString();
            if ($row->status !== TenantEntitlement::ACTIVE || ($previous !== null && $previous <= $lastDay)) {
                return $row;
            }
            $this->close($row, $lastDay, $actor, $reason);
            $this->bump($profile, $actor);
            $this->recordAudit(AuditAction::EntitlementEnded, $tenant, $row, $reason, $actor, ['capability' => $row->capability->value,
                'effective_to' => $row->status === TenantEntitlement::ACTIVE ? $lastDay : null, 'previous_effective_to' => $previous, 'status' => $row->status, 'version' => $profile->version]);

            return $row;
        });
    }

    /** An explicit exception that wins over the configuration from $from (today or later) until $to. */
    public function grantOverride(Tenant $tenant, Capability $capability, bool|int|null $value, string $from, ?string $to, string $reason, User $actor, ?string $reference = null): EntitlementOverride
    {
        $this->guard($actor, $reason);
        [$valueBool, $valueInt] = $this->value($capability, $value);
        [$from, $to] = [$this->startDay($from), $this->endDay($to, $from)];

        return $this->locked($tenant, function (TenantEntitlementProfile $profile) use ($tenant, $capability, $valueBool, $valueInt, $from, $to, $reason, $actor, $reference) {
            $overlapping = EntitlementOverride::query()->where('capability', $capability->value)->where('status', EntitlementOverride::ACTIVE)->lockForUpdate()->get()
                ->filter(fn (EntitlementOverride $o) => $this->overlaps($o->effective_from->toDateString(), $o->effective_to?->toDateString(), $from, $to));
            $same = $overlapping->first(fn (EntitlementOverride $o) => $o->value_bool === $valueBool && $o->value_int === $valueInt
                && $o->effective_from->toDateString() === $from && $o->effective_to?->toDateString() === $to);
            if ($same !== null) {
                return $same;
            }
            if ($overlapping->isNotEmpty()) {
                throw new RuntimeException("Another override for {$capability->value} covers part of this period (#{$overlapping->first()->id}). Revoke it first.");
            }

            $override = EntitlementOverride::query()->create(['capability' => $capability, 'value_bool' => $valueBool, 'value_int' => $valueInt, 'effective_from' => $from,
                'effective_to' => $to, 'status' => EntitlementOverride::ACTIVE, 'reason' => $reason, 'reference' => $reference, 'created_by' => $actor->id]);
            $this->bump($profile, $actor);
            $this->recordAudit(AuditAction::EntitlementOverrideGranted, $tenant, $override, $reason, $actor, ['capability' => $capability->value, 'value' => $valueBool ?? $valueInt,
                'effective_from' => $from, 'effective_to' => $to, 'reference' => $reference, 'version' => $profile->version]);

            return $override;
        });
    }

    /** Revoked from today: a started override ends yesterday, an override not yet started is cancelled. */
    public function revokeOverride(EntitlementOverride $override, string $reason, User $actor): EntitlementOverride
    {
        $this->guard($actor, $reason);
        $tenant = Tenant::query()->findOrFail($override->tenant_id);

        return $this->locked($tenant, function (TenantEntitlementProfile $profile) use ($tenant, $override, $reason, $actor) {
            $override = EntitlementOverride::query()->whereKey($override->id)->lockForUpdate()->firstOrFail();
            if ($override->status !== EntitlementOverride::ACTIVE || ($override->effective_to !== null && $override->effective_to->toDateString() < $this->today())) {
                return $override; // already revoked or already over
            }
            $this->close($override, $this->yesterday(), $actor, $reason);
            $this->bump($profile, $actor);
            $this->recordAudit(AuditAction::EntitlementOverrideRevoked, $tenant, $override, $reason, $actor, ['capability' => $override->capability->value,
                'status' => $override->status, 'effective_to' => $override->effective_to?->toDateString(), 'version' => $profile->version]);

            return $override;
        });
    }

    /**
     * Makes room for [$from, $to] in the active configuration rows: a row that started earlier ends the day before
     * $from; a row starting inside the range (which has not started yet, since $from is today or later) is
     * cancelled; a row continuing past $to resumes the day after $to as a new row with its own value.
     *
     * @param  Collection<int, TenantEntitlement>  $rows
     * @return array{ended: list<int>, cancelled: list<int>, continued: list<int>}
     */
    private function paint($rows, string $from, ?string $to, TenantEntitlement $new, User $actor, string $reason): array
    {
        $changes = ['ended' => [], 'cancelled' => [], 'continued' => []];
        foreach ($rows as $row) {
            [$rowFrom, $rowTo] = [$row->effective_from->toDateString(), $row->effective_to?->toDateString()];
            if (! $this->overlaps($rowFrom, $rowTo, $from, $to)) {
                continue;
            }
            if ($to !== null && ($rowTo === null || $rowTo > $to)) {
                $continuation = $row->replicate(['active_from', 'closed_by', 'closed_at', 'close_reason', 'superseded_by'])->fill([
                    'effective_from' => $this->dayAfter($to), 'effective_to' => $rowTo, 'reason' => "Continuation of #{$row->id} after {$to}", 'created_by' => $actor->id,
                ]);
                $continuations[] = $continuation;
            }
            $this->close($row, $this->dayBefore($from), $actor, $reason);
            $changes[$row->status === TenantEntitlement::CANCELLED ? 'cancelled' : 'ended'][] = $row->id;
        }
        foreach ($continuations ?? [] as $continuation) {
            $continuation->save();
            $changes['continued'][] = $continuation->id;
        }

        return $changes;
    }

    /** Ends a row on $lastDay, or cancels it when that is before its start (it never took effect). */
    private function close(TenantEntitlement|EntitlementOverride $row, string $lastDay, User $actor, string $reason): void
    {
        $neverStarted = $lastDay < $row->effective_from->toDateString();
        $row->forceFill($neverStarted
            ? ['status' => $row::CANCELLED, 'closed_by' => $actor->id, 'closed_at' => now(), 'close_reason' => $reason]
            : ['effective_to' => $lastDay, 'closed_by' => $actor->id, 'closed_at' => now(), 'close_reason' => $reason])->save();
    }

    /**
     * Runs $work with the tenant bound and its profile row locked (created first, outside the transaction, so two
     * first-time writers never deadlock on the insert). The cached state is forgotten after commit.
     */
    private function locked(Tenant $tenant, \Closure $work): mixed
    {
        return $this->tenants->runAs($tenant, function () use ($tenant, $work) {
            TenantEntitlementProfile::query()->insertOrIgnore(['tenant_id' => $tenant->id, 'state' => TenantEntitlementProfile::UNCONFIGURED, 'version' => 0,
                'created_at' => now(), 'updated_at' => now()]);

            return DB::transaction(function () use ($tenant, $work) {
                $profile = TenantEntitlementProfile::query()->lockForUpdate()->firstOrFail();
                $result = $work($profile);
                DB::afterCommit(fn () => app(EntitlementStateStore::class)->forget($tenant->id));

                return $result;
            });
        });
    }

    private function bump(TenantEntitlementProfile $profile, User $actor): void
    {
        $profile->forceFill(['version' => $profile->version + 1, 'updated_by' => $actor->id])->save();
    }

    /** @param  array<string, mixed>  $metadata */
    private function recordAudit(AuditAction $action, Tenant $tenant, Model $entity, string $reason, User $actor, array $metadata): void
    {
        $metadata += ['subject_tenant_id' => $tenant->id, 'subject_tenant' => $tenant->slug];
        $this->audit->record($action, 'entitlements', $entity, [], $reason, tenantId: $tenant->id, actor: $actor, metadata: $metadata);
        $this->audit->record($action, 'entitlements', null, [], $reason, entityLabel: "Tenant {$tenant->name}", actor: $actor, metadata: $metadata + ['entity_id' => $entity->getKey()], platform: true);
    }

    private function guard(User $actor, string $reason): void
    {
        if (! $actor->isPlatformAdmin()) {
            throw new RuntimeException('Only platform operators can change commercial entitlements.');
        }
        if (Str::length(trim($reason)) < 5) {
            throw new RuntimeException('A reason is required.');
        }
    }

    /** @return array{0: ?bool, 1: ?int} */
    private function value(Capability $capability, bool|int|null $value): array
    {
        if (! $capability->commercial()) {
            throw new RuntimeException("{$capability->value} is not a commercial capability.");
        }
        if ($capability->type() === CapabilityType::Limit) {
            if (is_bool($value) || (is_int($value) && $value < 0)) {
                throw new RuntimeException("{$capability->value} takes a whole number of {$capability->unit()} (or unlimited).");
            }

            return [null, $value];
        }
        if (! is_bool($value)) {
            throw new RuntimeException("{$capability->value} is on or off.");
        }

        return [$value, null];
    }

    private function startDay(string $day): string
    {
        $day = $this->parse($day);
        if ($day < $this->today()) {
            throw new RuntimeException('Changes start today or later: past days keep their answer.');
        }

        return $day;
    }

    private function endDay(?string $day, ?string $from): ?string
    {
        if ($day === null || trim($day) === '') {
            return null;
        }
        $day = $this->parse($day);
        if ($from !== null && $day < $from) {
            throw new RuntimeException('The last day cannot be before the first day.');
        }

        return $day;
    }

    private function parse(string $day): string
    {
        $parsed = Carbon::createFromFormat('!Y-m-d', $day);
        if ($parsed === false || $parsed->toDateString() !== $day) {
            throw new RuntimeException("{$day} is not a date (YYYY-MM-DD).");
        }

        return $day;
    }

    private function overlaps(string $aFrom, ?string $aTo, string $bFrom, ?string $bTo): bool
    {
        return ($aTo === null || $aTo >= $bFrom) && ($bTo === null || $bTo >= $aFrom);
    }

    private function today(): string
    {
        return now()->toDateString();
    }

    private function yesterday(): string
    {
        return now()->subDay()->toDateString();
    }

    private function dayBefore(string $day): string
    {
        return Carbon::parse($day)->subDay()->toDateString();
    }

    private function dayAfter(string $day): string
    {
        return Carbon::parse($day)->addDay()->toDateString();
    }
}
