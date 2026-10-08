<?php

namespace App\Domain\Subscriptions\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Entitlements\Models\PlanVersion;
use App\Domain\Entitlements\Models\TenantEntitlementProfile;
use App\Domain\Entitlements\Services\EntitlementConfiguration;
use App\Domain\Entitlements\Services\TenantCommercialLock;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Subscriptions\Enums\CommercialStatus as S;
use App\Domain\Subscriptions\Events\CommercialStatusChanged;
use App\Domain\Subscriptions\Models\SubscriptionPeriod;
use App\Domain\Subscriptions\Models\TenantSubscription;
use App\Domain\Subscriptions\Support\SubscriptionTimeline;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SaaS.6: the only way a tenant's commercial subscription changes. Platform operators only (refused here, not just
 * hidden), a reason on every change, audited on the tenant chain and Markedge's platform chain with the state and
 * plan version before and after, the effective date and the trigger. No money is involved anywhere.
 *
 * - Effective-dated, append-only timeline (SubscriptionPeriod). A change takes effect from a business date (today or
 *   later; UTC): the period in force ends the day before, periods that would start on or after it are voided (they
 *   never took effect) and the new periods start. History is never rewritten.
 * - Legal transitions are judged on the state in force on the effective date (SubscriptionTimeline), so dates, not
 *   who acts first, decide: a trial that ended yesterday is expired today whether or not the scheduler has run.
 * - Idempotent: the same change twice (same states, versions and dates from the same day) changes nothing.
 * - One live subscription per tenant: a new one may start only on or after the previous one is cancelled.
 * - Projection: in the same transaction, under the same tenant lock (TenantCommercialLock), every trial, active and
 *   grace period becomes the tenant's plan assignment for exactly its dates (EntitlementConfiguration::
 *   projectSubscription). The entitlement engine keeps reading assignments only; a lapsed or cancelled subscription
 *   simply has no plan in force (UNKNOWN, never DENY). Nothing is enforced.
 * - The scheduler (settle) only materialises an expiry the dates already decided, effective the day after the end.
 */
final class CommercialSubscriptions
{
    /** Whether the last apply() recorded a change (false when it found the same change already made). */
    private bool $changed = false;

    public function __construct(private readonly TenantCommercialLock $lock, private readonly EntitlementConfiguration $entitlements,
        private readonly AuditRecorder $audit, private readonly TenantContext $tenants) {}

    /** A new subscription in trial of $version from $from (today or later) until $until (explicit, inclusive). */
    public function startTrial(Tenant $tenant, PlanVersion $version, string $from, string $until, string $reason, User $actor, ?string $reference = null): TenantSubscription
    {
        $this->guard($actor, $reason);
        [$from, $until] = [$this->startDay($from), $this->endDay($until, $from)];

        return $this->apply($tenant, null, $reason, $actor, $reference, function () use ($version, $from, $until) {
            $this->assertOnSale($version, $from);

            return [$from, [[S::Trial, $version->id, $from, $until]], AuditAction::TrialStarted];
        });
    }

    /** A new subscription, active on $version from $from, open-ended or until $until. */
    public function start(Tenant $tenant, PlanVersion $version, string $from, ?string $until, string $reason, User $actor, ?string $reference = null): TenantSubscription
    {
        $this->guard($actor, $reason);
        [$from, $until] = [$this->startDay($from), $this->optionalEnd($until, $from)];

        return $this->apply($tenant, null, $reason, $actor, $reference, function () use ($version, $from, $until) {
            $this->assertOnSale($version, $from);

            return [$from, [[S::Active, $version->id, $from, $until]], AuditAction::SubscriptionActivated];
        });
    }

    /**
     * The current trial, term or grace continues past its end, until $until (an active term may become open-ended
     * with null). It continues from the day after its current end, replacing anything scheduled after it (a
     * scheduled cancellation or expiry). Only a period that has not lapsed can be extended.
     */
    public function extend(TenantSubscription $subscription, ?string $until, string $reason, User $actor, ?string $reference = null): TenantSubscription
    {
        $this->guard($actor, $reason);
        $until = $until === null || trim($until) === '' ? null : $this->parse($until);

        return $this->apply($this->tenantOf($subscription), $subscription, $reason, $actor, $reference, function (SubscriptionTimeline $t) use ($until) {
            $today = $this->today();
            $target = collect($t->periods)->first(fn (array $p) => $p['to'] === null || $p['to'] >= $today);
            if ($target === null || ! $target['status']->entitled()) {
                throw new RuntimeException('Nothing to extend: the subscription has lapsed or ended (reactivate it instead).');
            }
            if ($target['to'] === null) {
                throw new RuntimeException("The {$target['status']->value} period is already open-ended.");
            }
            if ($until === null && $target['status'] !== S::Active) {
                throw new RuntimeException("A {$target['status']->value} period needs an explicit end date.");
            }
            if ($until !== null && $until <= $target['to']) {
                throw new RuntimeException("The new end must be after the current end ({$target['to']}).");
            }
            $from = $this->dayAfter($target['to']);
            $action = match ($target['status']) {
                S::Trial => AuditAction::TrialExtended,
                S::Grace => AuditAction::GraceExtended,
                default => AuditAction::SubscriptionRenewed,
            };

            return [$from, [[$target['status'], $target['plan_version_id'], $from, $until]], $action];
        });
    }

    /** The trial in force on $from becomes active from $from (same plan version), open-ended or until $until. */
    public function convert(TenantSubscription $subscription, string $from, ?string $until, string $reason, User $actor, ?string $reference = null): TenantSubscription
    {
        $this->guard($actor, $reason);
        [$from, $until] = [$this->startDay($from), $this->optionalEnd($until, $from)];

        return $this->apply($this->tenantOf($subscription), $subscription, $reason, $actor, $reference, function (SubscriptionTimeline $t) use ($from, $until) {
            $state = $this->inForce($t, $from, [S::Trial], 'converted', [S::Active], $until);

            return [$from, [[S::Active, $state['plan_version_id'], $from, $until]], AuditAction::TrialConverted];
        });
    }

    /** The active subscription enters an explicit grace window from $from until $until (still entitled). */
    public function enterGrace(TenantSubscription $subscription, string $from, string $until, string $reason, User $actor, ?string $reference = null): TenantSubscription
    {
        $this->guard($actor, $reason);
        [$from, $until] = [$this->startDay($from), $this->endDay($until, $from)];

        return $this->apply($this->tenantOf($subscription), $subscription, $reason, $actor, $reference, function (SubscriptionTimeline $t) use ($from, $until) {
            $state = $this->inForce($t, $from, [S::Active], 'put into grace', [S::Grace], $until);

            return [$from, [[S::Grace, $state['plan_version_id'], $from, $until]], AuditAction::GraceEntered];
        });
    }

    /** A subscription in grace or expired becomes active again from $from (same plan version). */
    public function reactivate(TenantSubscription $subscription, string $from, ?string $until, string $reason, User $actor, ?string $reference = null): TenantSubscription
    {
        $this->guard($actor, $reason);
        [$from, $until] = [$this->startDay($from), $this->optionalEnd($until, $from)];

        return $this->apply($this->tenantOf($subscription), $subscription, $reason, $actor, $reference, function (SubscriptionTimeline $t) use ($from, $until) {
            $state = $this->inForce($t, $from, [S::Grace, S::Expired], 'reactivated', [S::Active], $until, allowDerived: true);

            return [$from, [[S::Active, $state['plan_version_id'], $from, $until]], AuditAction::SubscriptionReactivated];
        });
    }

    /** The trial, term or grace in force ends early: expired from $from. */
    public function expire(TenantSubscription $subscription, string $from, string $reason, User $actor, ?string $reference = null): TenantSubscription
    {
        $this->guard($actor, $reason);
        $from = $this->startDay($from);

        return $this->apply($this->tenantOf($subscription), $subscription, $reason, $actor, $reference, function (SubscriptionTimeline $t) use ($from) {
            $state = $this->inForce($t, $from, [S::Trial, S::Active, S::Grace], 'expired', [S::Expired], null);

            return [$from, [[S::Expired, $state['plan_version_id'], $from, null]], AuditAction::SubscriptionExpired];
        });
    }

    /** The subscription is cancelled from $from (terminal; future-dated allowed: the current state runs until the day before). */
    public function cancel(TenantSubscription $subscription, string $from, string $reason, User $actor, ?string $reference = null): TenantSubscription
    {
        $this->guard($actor, $reason);
        $from = $this->startDay($from);

        return $this->apply($this->tenantOf($subscription), $subscription, $reason, $actor, $reference, function (SubscriptionTimeline $t) use ($from) {
            $state = $t->stateOn($from);
            $cancelled = $t->cancelledFrom();
            if ($cancelled !== null && $cancelled === $from) {
                return [$from, [[S::Cancelled, $t->last()['plan_version_id'], $from, null]], AuditAction::SubscriptionCancelled]; // the same cancellation again
            }
            if ($cancelled !== null && $cancelled < $from) {
                throw new RuntimeException("The subscription is already cancelled from {$cancelled}.");
            }

            return [$from, [[S::Cancelled, $state['plan_version_id'] ?? $t->first()['plan_version_id'], $from, null]], AuditAction::SubscriptionCancelled];
        });
    }

    /**
     * The subscription moves to another published version from $from (an explicit, reasoned operator decision; never
     * automatic). States and dates from $from are kept: only the plan version changes.
     */
    public function changePlan(TenantSubscription $subscription, PlanVersion $version, string $from, string $reason, User $actor, ?string $reference = null): TenantSubscription
    {
        $this->guard($actor, $reason);
        $from = $this->startDay($from);

        return $this->apply($this->tenantOf($subscription), $subscription, $reason, $actor, $reference, function (SubscriptionTimeline $t) use ($version, $from) {
            $tail = $t->tailFrom($from);
            $periods = array_map(fn (array $p) => [S::from($p['status']), S::from($p['status'])->entitled() ? $version->id : $p['plan_version_id'], $p['from'], $p['to']], $tail);
            if ($this->normalise($periods) === $tail) {
                return [$from, $periods, AuditAction::SubscriptionPlanChanged]; // the same change again
            }
            $state = $t->stateOn($from);
            if ($state === null || $state['derived'] || ! $state['status']->entitled()) {
                throw new RuntimeException("Only a trial, active or grace subscription can change plan; on {$from} it is ".($state['status']->value ?? 'not started').'.');
            }
            $this->assertOnSale($version, $from);

            return [$from, $periods, AuditAction::SubscriptionPlanChanged];
        });
    }

    /**
     * Scheduler: records, for the bound tenant, every expiry the dates already decided (a trial, term or grace that
     * ended with no successor), effective the day after the end however late this runs. Safe to run twice, to
     * retry and to delay. Returns the expired periods it recorded.
     *
     * @return list<SubscriptionPeriod>
     */
    public function settle(Tenant $tenant): array
    {
        $recorded = [];
        $subscriptions = $this->tenants->runAs($tenant, fn () => TenantSubscription::query()->with('periods')->orderBy('id')->get());
        foreach ($subscriptions as $subscription) {
            $lapse = $subscription->timeline()->lapse();
            if ($lapse === null || $lapse['from'] > $this->today()) {
                continue;
            }
            $reason = "The {$lapse['lapsed']->value} ended on ".Carbon::parse($lapse['from'])->subDay()->toDateString().' with no successor';
            $after = $this->apply($tenant, $subscription, $reason, null, null, function (SubscriptionTimeline $t) use ($lapse) {
                $now = $t->lapse();
                if ($now === null) { // settled meanwhile, or changed by an operator: replay what is there (nothing is recorded)
                    return [$lapse['from'], array_map(fn (array $p) => [S::from($p['status']), $p['plan_version_id'], $p['from'], $p['to']], $t->tailFrom($lapse['from'])), AuditAction::SubscriptionExpired];
                }

                return [$now['from'], [[S::Expired, $now['plan_version_id'], $now['from'], null]], AuditAction::SubscriptionExpired];
            }, trigger: 'scheduler');
            if ($this->changed) {
                $recorded[] = $after->periods->whereNull('voided_at')->last();
            }
        }

        return $recorded;
    }

    /**
     * @param  Closure(SubscriptionTimeline): array{0: string, 1: list<array{0: S, 1: int, 2: string, 3: ?string}>, 2: AuditAction}  $plan
     */
    private function apply(Tenant $tenant, ?TenantSubscription $subscription, string $reason, ?User $actor, ?string $reference, Closure $plan, string $trigger = 'operator'): TenantSubscription
    {
        $this->changed = false;
        if (Str::length($reason) > 500 || Str::length((string) $reference) > 100) {
            throw new RuntimeException('The reason is limited to 500 characters and the reference to 100.');
        }

        return $this->lock->run($tenant, function (TenantEntitlementProfile $profile) use ($tenant, $subscription, $reason, $actor, $reference, $plan, $trigger) {
            $current = $subscription === null ? null : TenantSubscription::query()->with('periods')->whereKey($subscription->id)->firstOrFail();
            $timeline = $current?->timeline() ?? SubscriptionTimeline::of([]);
            [$from, $periods, $action] = $plan($timeline);
            if ($current !== null && $timeline->tailFrom($from) === $this->normalise($periods)) {
                return $current; // the same change again: nothing to record
            }
            if ($current === null) {
                $this->assertNoLiveSubscription($tenant, $from);
                $current = TenantSubscription::query()->create(['reason' => $reason, 'reference' => $reference, 'created_by' => $actor?->id]);
            }
            $before = $timeline->stateOn($from);
            $changes = $this->paint($current, $from, $periods, $trigger, $reason, $reference, $actor);
            $segments = array_values(array_map(fn (array $p) => ['plan_version_id' => $p[1], 'from' => $p[2], 'to' => $p[3], 'status' => $p[0]->value],
                array_filter($periods, fn (array $p) => $p[0]->entitled())));
            $projection = $this->entitlements->projectSubscription($tenant, $profile, $current->id, $from, $segments, $actor, $reason);
            $this->record($action, $tenant, $current, $before, $periods, $from, $reason, $reference, $actor, $trigger, $changes, $projection);
            $event = new CommercialStatusChanged($tenant->id, $current->id, $action->value, $before === null ? null : $before['status']->value, $periods[0][0]->value, $from, $trigger);
            DB::afterCommit(fn () => event($event));
            $this->changed = true;

            return $current->fresh('periods');
        });
    }

    /**
     * Ends the period in force on the day before $from (end brought earlier), voids periods that would start on or
     * after $from (they never took effect), and records the new periods.
     *
     * @param  list<array{0: S, 1: int, 2: string, 3: ?string}>  $periods
     * @return array{ended: list<int>, voided: list<int>, created: list<int>}
     */
    private function paint(TenantSubscription $subscription, string $from, array $periods, string $trigger, string $reason, ?string $reference, ?User $actor): array
    {
        $changes = ['ended' => [], 'voided' => [], 'created' => []];
        $rows = SubscriptionPeriod::query()->where('subscription_id', $subscription->id)->whereNull('voided_at')->orderBy('starts_on')->lockForUpdate()->get();
        foreach ($rows as $row) {
            $start = $row->starts_on->toDateString();
            $end = $row->ends_on?->toDateString();
            if ($start >= $from) {
                $row->forceFill(['voided_at' => now(), 'closed_by' => $actor?->id, 'closed_at' => now(), 'close_reason' => $reason])->save();
                $changes['voided'][] = $row->id;
            } elseif ($end === null || $end >= $from) {
                $row->forceFill(['ends_on' => $this->dayBefore($from), 'closed_by' => $actor?->id, 'closed_at' => now(), 'close_reason' => $reason])->save();
                $changes['ended'][] = $row->id;
            }
        }
        foreach ($periods as [$status, $versionId, $start, $end]) {
            $changes['created'][] = SubscriptionPeriod::query()->create(['subscription_id' => $subscription->id, 'status' => $status, 'plan_version_id' => $versionId,
                'starts_on' => $start, 'ends_on' => $end, 'trigger' => $trigger, 'reason' => $reason, 'reference' => $reference, 'created_by' => $actor?->id])->id;
        }
        if ($changes['voided'] !== [] && $changes['created'] !== []) {
            SubscriptionPeriod::query()->whereKey($changes['voided'])->get()->each(fn (SubscriptionPeriod $p) => $p->forceFill(['superseded_by' => $changes['created'][0]])->save());
        }

        return $changes;
    }

    /**
     * @param  array{status: S, plan_version_id: int}|null  $before
     * @param  list<array{0: S, 1: int, 2: string, 3: ?string}>  $periods
     * @param  array<string, list<int>>  $changes
     * @param  array<string, list<int>>  $projection
     */
    private function record(AuditAction $action, Tenant $tenant, TenantSubscription $subscription, ?array $before, array $periods, string $from, string $reason,
        ?string $reference, ?User $actor, string $trigger, array $changes, array $projection): void
    {
        $labels = PlanVersion::query()->with('plan')->whereKey(array_filter([$before['plan_version_id'] ?? null, $periods[0][1]]))->get()->mapWithKeys(fn (PlanVersion $v) => [$v->id => $v->label()]);
        $fieldChanges = [['field' => 'commercial_status', 'before' => $before === null ? 'none' : $before['status']->value.($before['derived'] ? ' (lapsed)' : ''), 'after' => $periods[0][0]->value]];
        if (($before['plan_version_id'] ?? null) !== $periods[0][1]) {
            $fieldChanges[] = ['field' => 'plan_version', 'before' => $labels[$before['plan_version_id'] ?? 0] ?? 'none', 'after' => $labels[$periods[0][1]] ?? (string) $periods[0][1]];
        }
        $metadata = ['subject_tenant_id' => $tenant->id, 'subject_tenant' => $tenant->slug, 'subscription_id' => $subscription->id, 'trigger' => $trigger,
            'effective_from' => $from, 'until' => $periods[array_key_last($periods)][3], 'reference' => $reference,
            'periods' => $changes, 'assignments' => $projection];
        $this->audit->record($action, 'subscriptions', $subscription, $fieldChanges, $reason, effectiveDate: $from, tenantId: $tenant->id, actor: $actor, metadata: $metadata);
        $this->audit->record($action, 'subscriptions', null, $fieldChanges, $reason, effectiveDate: $from, entityLabel: "Tenant {$tenant->name} · subscription #{$subscription->id}",
            actor: $actor, metadata: $metadata + ['entity_id' => $subscription->id, 'entity_type' => 'TenantSubscription'], platform: true);
    }

    /**
     * The state in force on $from must be one of $allowed (a derived expiry only where $allowDerived), unless the
     * same change was already made (the subscription is already $already from $from with the same end).
     *
     * @param  list<S>  $allowed
     * @param  list<S>  $already
     * @return array{status: S, plan_version_id: int, derived: bool}
     */
    private function inForce(SubscriptionTimeline $t, string $from, array $allowed, string $verb, array $already, ?string $until, bool $allowDerived = false): array
    {
        $state = $t->stateOn($from);
        if ($state !== null && in_array($state['status'], $already, true) && ! $state['derived'] && $state['from'] === $from && $state['to'] === $until) {
            return $state; // the same change again (apply() records nothing)
        }
        if ($state === null || ! in_array($state['status'], $allowed, true) || ($state['derived'] && ! $allowDerived)) {
            $now = $state === null ? 'not started' : $state['status']->value.($state['derived'] ? ' (its end has passed)' : '');
            throw new RuntimeException('Only a subscription that is '.implode(' or ', array_map(fn (S $s) => $s->value, $allowed))." on {$from} can be {$verb}; on {$from} it is {$now}.");
        }

        return $state;
    }

    private function assertNoLiveSubscription(Tenant $tenant, string $from): void
    {
        foreach (TenantSubscription::query()->with('periods')->get() as $existing) {
            $cancelled = $existing->timeline()->cancelledFrom();
            if ($cancelled === null || $cancelled > $from) {
                throw new RuntimeException("{$tenant->name} already has subscription #{$existing->id}; a new one can start only on or after that one is cancelled.");
            }
        }
    }

    private function assertOnSale(PlanVersion $version, string $from): void
    {
        $version = PlanVersion::query()->with('plan')->whereKey($version->id)->sharedLock()->firstOrFail();
        if ($version->status !== VersionStatus::Published || ! $version->onSaleOn($from)) {
            throw new RuntimeException("{$version->label()} is not on sale on {$from} (it must be published and inside its sale window).");
        }
    }

    /**
     * @param  list<array{0: S, 1: int, 2: string, 3: ?string}>  $periods
     * @return list<array{status: string, plan_version_id: int, from: string, to: ?string}>
     */
    private function normalise(array $periods): array
    {
        return array_map(fn (array $p) => ['status' => $p[0]->value, 'plan_version_id' => (int) $p[1], 'from' => $p[2], 'to' => $p[3]], $periods);
    }

    private function tenantOf(TenantSubscription $subscription): Tenant
    {
        return Tenant::query()->findOrFail($subscription->tenant_id);
    }

    private function guard(User $actor, string $reason): void
    {
        if (! $actor->isPlatformAdmin()) {
            throw new RuntimeException('Only platform operators can change commercial subscriptions.');
        }
        if (Str::length(trim($reason)) < 5) {
            throw new RuntimeException('A reason is required.');
        }
    }

    private function startDay(string $day): string
    {
        $day = $this->parse($day);
        if ($day < $this->today()) {
            throw new RuntimeException('Commercial changes take effect today or later: past days keep their state.');
        }

        return $day;
    }

    private function endDay(string $day, string $from): string
    {
        $day = $this->parse($day);
        if ($day < $from) {
            throw new RuntimeException('The end date cannot be before the start date.');
        }

        return $day;
    }

    private function optionalEnd(?string $day, string $from): ?string
    {
        return $day === null || trim($day) === '' ? null : $this->endDay($day, $from);
    }

    private function parse(string $day): string
    {
        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $day);
        } catch (\InvalidArgumentException) {
            $parsed = false;
        }
        if ($parsed === false || $parsed->toDateString() !== $day) {
            throw new RuntimeException("{$day} is not a date (YYYY-MM-DD).");
        }

        return $day;
    }

    private function today(): string
    {
        return now()->toDateString();
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
