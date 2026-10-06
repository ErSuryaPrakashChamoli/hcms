<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Services\PlanCatalog;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Subscriptions\Enums\CommercialStatus as S;
use App\Domain\Subscriptions\Models\SubscriptionPeriod;
use App\Domain\Subscriptions\Models\TenantSubscription;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Support\Tenancy\TenantContext;

require_once __DIR__.'/../Entitlements/PlanTestHelpers.php';

/*
| SaaS.6: the commercial subscription state machine. Legal transitions judged on the state in force on the effective
| date; effective-dated, append-only history; future-dated changes; idempotency; one live subscription per tenant;
| plan versions pinned and never moved by the catalogue. Fictional plans only; no money anywhere.
*/

beforeEach(function () {
    $this->travelTo('2027-03-01 09:00:00');
    $this->tenant = provisionTenant('Alpha');
    $this->operator = platformAdmin();
    $this->subs = app(CommercialSubscriptions::class);
    $this->growth = publishedPlan($this->operator, 'growth', ['leave' => true, 'payroll' => true, 'active_employees.max' => 100], '2027-03-01');
    $this->starter = publishedPlan($this->operator, 'starter', ['leave' => true, 'active_employees.max' => 25], '2027-03-01');
});

/** The tenant's subscription state on a day: [status value, plan version id, derived?] (null = none). */
function stateOf(TenantSubscription $subscription, string $day): ?array
{
    $s = app(TenantContext::class)->runAs(Tenant::query()->findOrFail($subscription->tenant_id), fn () => TenantSubscription::query()->with('periods')->findOrFail($subscription->id)->timeline()->stateOn($day));

    return $s === null ? null : [$s['status']->value, $s['plan_version_id'], $s['derived']];
}

it('runs the full lifecycle: trial, extension, conversion, grace, reactivation, cancellation, each from its own date', function () {
    $sub = $this->subs->startTrial($this->tenant, $this->growth, '2027-03-01', '2027-03-14', 'Two-week trial', $this->operator, 'DEAL-1');
    $sub = $this->subs->extend($sub, '2027-03-21', 'Customer asked for one more week', $this->operator);
    $sub = $this->subs->convert($sub, '2027-03-10', null, 'Contract signed', $this->operator, 'PO-77');
    $this->travelTo('2027-04-01 09:00:00');
    $sub = $this->subs->enterGrace($sub, '2027-04-01', '2027-04-14', 'Renewal paperwork pending', $this->operator);
    $sub = $this->subs->reactivate($sub, '2027-04-05', null, 'Renewal signed', $this->operator);
    $sub = $this->subs->cancel($sub, '2027-06-01', 'Customer leaves at quarter end', $this->operator);

    $g = $this->growth->id;
    expect(stateOf($sub, '2027-03-05'))->toBe(['trial', $g, false])
        ->and(stateOf($sub, '2027-03-10'))->toBe(['active', $g, false])   // converted mid-trial: the extension never took effect
        ->and(stateOf($sub, '2027-03-31'))->toBe(['active', $g, false])
        ->and(stateOf($sub, '2027-04-02'))->toBe(['grace', $g, false])
        ->and(stateOf($sub, '2027-04-05'))->toBe(['active', $g, false])
        ->and(stateOf($sub, '2027-05-31'))->toBe(['active', $g, false])
        ->and(stateOf($sub, '2027-06-01'))->toBe(['cancelled', $g, false])
        ->and(stateOf($sub, '2027-02-28'))->toBeNull();

    // History is append-only: every row is still there; replaced future rows are voided, never deleted.
    $rows = app(TenantContext::class)->runAs($this->tenant, fn () => SubscriptionPeriod::query()->orderBy('id')->get());
    expect($rows->map(fn ($p) => [$p->status->value, $p->starts_on->toDateString(), $p->ends_on?->toDateString(), $p->voided_at !== null])->all())->toBe([
        ['trial', '2027-03-01', '2027-03-09', false],
        ['trial', '2027-03-15', '2027-03-21', true],     // the extension, voided by the conversion before it started
        ['active', '2027-03-10', '2027-03-31', false],
        ['grace', '2027-04-01', '2027-04-04', false],
        ['active', '2027-04-05', '2027-05-31', false],
        ['cancelled', '2027-06-01', null, false],
    ]);
    $actions = AuditEvent::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->where('module', 'subscriptions')->orderBy('id')->pluck('action')->map->value->all();
    expect($actions)->toBe(['TRIAL_STARTED', 'TRIAL_EXTENDED', 'TRIAL_CONVERTED', 'GRACE_ENTERED', 'SUBSCRIPTION_REACTIVATED', 'SUBSCRIPTION_CANCELLED']);
});

it('refuses illegal transitions, judged on the state in force on the effective date', function () {
    $sub = $this->subs->start($this->tenant, $this->growth, '2027-03-01', null, 'Direct contract', $this->operator);
    $refused = [
        'convert an active subscription' => [fn () => $this->subs->convert($sub, '2027-03-05', null, 'Not a trial', $this->operator), 'can be converted; on 2027-03-05 it is active'],
        'reactivate an active subscription' => [fn () => $this->subs->reactivate($sub, '2027-03-05', null, 'Nothing to reactivate', $this->operator), 'can be reactivated'],
        'extend an open-ended term' => [fn () => $this->subs->extend($sub, '2027-12-31', 'Already open', $this->operator), 'already open-ended'],
        'change before it began' => [fn () => $this->subs->expire($sub, '2027-02-28', 'Before the start', $this->operator), 'today or later'],
        'start a second live subscription' => [fn () => $this->subs->startTrial($this->tenant, $this->starter, '2027-03-02', '2027-03-20', 'Second one', $this->operator), 'already has subscription'],
        'grace without an end' => [fn () => $this->subs->enterGrace($sub, '2027-03-05', '2027-03-04', 'End before start', $this->operator), 'cannot be before the start'],
        'an invalid date' => [fn () => $this->subs->cancel($sub, '2027-02-30', 'Not a date', $this->operator), 'not a date'],
    ];
    foreach ($refused as $what => [$attempt, $message]) {
        expect($attempt)->toThrow(RuntimeException::class, $message);
    }

    // A week later, the days already lived keep their state: no change can take effect on them, even where it would be legal.
    $this->travelTo('2027-03-08 09:00:00');
    expect(fn () => $this->subs->expire($sub, '2027-03-05', 'Back-dated expiry', $this->operator))->toThrow(RuntimeException::class, 'today or later')
        ->and(fn () => $this->subs->cancel($sub, '2027-03-07', 'Back-dated cancellation', $this->operator))->toThrow(RuntimeException::class, 'today or later')
        ->and(stateOf($sub, '2027-03-05'))->toBe(['active', $this->growth->id, false]);

    $this->subs->cancel($sub, '2027-03-10', 'Ends on the 10th', $this->operator);
    expect(fn () => $this->subs->reactivate($sub, '2027-03-10', null, 'Cancelled is terminal', $this->operator))->toThrow(RuntimeException::class, 'cancelled')
        ->and(fn () => $this->subs->cancel($sub, '2027-03-20', 'Later cancellation', $this->operator))->toThrow(RuntimeException::class, 'already cancelled')
        ->and(fn () => $this->subs->startTrial($this->tenant, $this->starter, '2027-03-09', '2027-03-20', 'Overlaps the old one', $this->operator))->toThrow(RuntimeException::class, 'already has subscription');

    // A returning customer gets a new subscription, from the cancellation date on.
    $new = $this->subs->startTrial($this->tenant, $this->starter, '2027-03-10', '2027-03-24', 'Back on a trial', $this->operator);
    expect($new->id)->not->toBe($sub->id)->and(stateOf($new, '2027-03-12'))->toBe(['trial', $this->starter->id, false]);
});

it('lets the dates decide expiry: a lapsed trial is expired before the scheduler runs, and cannot be converted any more', function () {
    $sub = $this->subs->startTrial($this->tenant, $this->growth, '2027-03-01', '2027-03-07', 'One-week trial', $this->operator);
    expect(stateOf($sub, '2027-03-08'))->toBe(['expired', $this->growth->id, true]);   // derived: no row yet

    $this->travelTo('2027-03-08 09:00:00');
    expect(fn () => $this->subs->convert($sub, '2027-03-08', null, 'Too late to convert', $this->operator))->toThrow(RuntimeException::class, 'its end has passed')
        ->and(fn () => $this->subs->extend($sub, '2027-03-20', 'Too late to extend', $this->operator))->toThrow(RuntimeException::class, 'lapsed');

    // A lapsed trial can be reactivated (a late conversion), from today on.
    $sub = $this->subs->reactivate($sub, '2027-03-08', null, 'Late signature', $this->operator);
    expect(stateOf($sub, '2027-03-08'))->toBe(['active', $this->growth->id, false])
        ->and(stateOf($sub, '2027-03-05'))->toBe(['trial', $this->growth->id, false]);
});

it('keeps future-dated changes as rows that start later, and lets a later decision void them', function () {
    $sub = $this->subs->start($this->tenant, $this->growth, '2027-03-01', null, 'Direct contract', $this->operator);
    $sub = $this->subs->cancel($sub, '2027-03-31', 'Notice given', $this->operator);
    expect(stateOf($sub, '2027-03-30'))->toBe(['active', $this->growth->id, false])->and(stateOf($sub, '2027-03-31')[0])->toBe('cancelled');

    // The customer stays after all: the term continues past its new end, voiding the scheduled cancellation.
    $sub = $this->subs->extend($sub, null, 'Customer withdrew the notice', $this->operator);
    expect(stateOf($sub, '2027-03-31'))->toBe(['active', $this->growth->id, false])
        ->and(stateOf($sub, '2028-01-01'))->toBe(['active', $this->growth->id, false])
        ->and(app(TenantContext::class)->runAs($this->tenant, fn () => SubscriptionPeriod::query()->where('status', 'cancelled')->sole()->voided_at))->not->toBeNull();
});

it('is idempotent: the same change twice records one row and one audit pair', function () {
    $sub = $this->subs->startTrial($this->tenant, $this->growth, '2027-03-01', '2027-03-14', 'Trial', $this->operator);
    $calls = [
        fn () => $this->subs->extend($sub, '2027-03-21', 'Extend', $this->operator),
        fn () => $this->subs->convert($sub, '2027-03-05', '2027-12-31', 'Convert', $this->operator),
        fn () => $this->subs->changePlan($sub, $this->starter, '2027-04-01', 'Downgrade from April', $this->operator),
        fn () => $this->subs->cancel($sub, '2027-06-01', 'Cancel in June', $this->operator),
    ];
    foreach ($calls as $call) {
        $call();
        $rows = SubscriptionPeriod::query()->withoutTenancy()->count();
        $audits = AuditEvent::query()->withoutTenancy()->where('module', 'subscriptions')->count();
        $call(); // the same request again (a retry, a double click)
        expect(SubscriptionPeriod::query()->withoutTenancy()->count())->toBe($rows)
            ->and(AuditEvent::query()->withoutTenancy()->where('module', 'subscriptions')->count())->toBe($audits);
    }
});

it('pins the plan version: publishing a new version never moves a subscription; only an explicit change does', function () {
    $sub = $this->subs->start($this->tenant, $this->growth, '2027-03-01', null, 'Growth v1', $this->operator);
    $catalog = app(PlanCatalog::class);
    $v2 = $catalog->draft($this->growth->plan, 'Growth v2', $this->operator);
    $catalog->set($v2, Capability::ActiveEmployeesMax, 500, 'Bigger', $this->operator);
    $catalog->publish($v2, '2027-03-02', 'v2 on sale', $this->operator);
    $catalog->retire($this->growth->fresh(), 'v1 withdrawn', $this->operator);

    expect(stateOf($sub, '2027-09-01'))->toBe(['active', $this->growth->id, false]);
    $this->travelTo('2027-03-02 09:00:00');
    $catalog->retire($this->starter->fresh(), 'Starter withdrawn', $this->operator);
    expect(fn () => $this->subs->changePlan($sub, $this->starter->fresh(), '2027-03-02', 'Move to a retired version', $this->operator))->toThrow(RuntimeException::class, 'not on sale');
    $sub = $this->subs->changePlan($sub, $v2->fresh(), '2027-04-01', 'Move to v2 at renewal', $this->operator);
    expect(stateOf($sub, '2027-03-31'))->toBe(['active', $this->growth->id, false])
        ->and(stateOf($sub, '2027-04-01'))->toBe(['active', $v2->id, false]);
    $event = AuditEvent::query()->withoutTenancy()->with('fieldChanges')->where('tenant_id', $this->tenant->id)->where('action', 'SUBSCRIPTION_PLAN_CHANGED')->sole();
    expect($event->fieldChanges->firstWhere('field', 'plan_version')?->only(['before', 'after']))->toBe(['before' => 'growth v1', 'after' => 'growth v2']);
});
