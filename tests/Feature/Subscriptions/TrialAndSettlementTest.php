<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\TenantSuspensions;
use App\Domain\Subscriptions\Events\CommercialStatusChanged;
use App\Domain\Subscriptions\Models\SubscriptionPeriod;
use App\Domain\Subscriptions\Models\TenantSubscription;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Entitlements/PlanTestHelpers.php';

/*
| SaaS.6: trials (explicit dates, extension, conversion, lapse) and the scheduled settlement that records the expiries
| the dates already decided: effective the day after the end however late it runs, idempotent, safe to retry, per
| tenant isolated, suspended tenants included, and nothing else changed. Commercial events are emitted; no mail.
*/

beforeEach(function () {
    $this->travelTo('2027-03-01 09:00:00');
    $this->tenant = provisionTenant('Alpha');
    $this->operator = platformAdmin();
    $this->subs = app(CommercialSubscriptions::class);
    $this->growth = publishedPlan($this->operator, 'growth', ['leave' => true, 'payroll' => true], '2027-03-01');
});

function periodsOf($tenant): array
{
    return app(TenantContext::class)->runAs($tenant, fn () => SubscriptionPeriod::query()->whereNull('voided_at')->orderBy('starts_on')->get()
        ->map(fn ($p) => [$p->status->value, $p->starts_on->toDateString(), $p->ends_on?->toDateString(), $p->trigger])->all());
}

it('takes trial dates as given (no global length), extends them with a reason and never converts on its own', function () {
    $short = $this->subs->startTrial($this->tenant, $this->growth, '2027-03-01', '2027-03-03', 'Three-day pilot', $this->operator);
    $other = provisionTenant('Beta');
    $this->subs->startTrial($other, $this->growth, '2027-03-05', '2027-05-04', 'Two-month pilot starting Friday', $this->operator);

    expect(periodsOf($this->tenant))->toBe([['trial', '2027-03-01', '2027-03-03', 'operator']])
        ->and(periodsOf($other))->toBe([['trial', '2027-03-05', '2027-05-04', 'operator']])
        ->and(fn () => $this->subs->startTrial(provisionTenant('Gamma'), $this->growth, '2027-03-01', '', 'No end date', $this->operator))->toThrow(RuntimeException::class, 'not a date');

    $this->subs->extend($short, '2027-03-10', 'Asked for another week', $this->operator);
    $extension = AuditEvent::query()->withoutTenancy()->with('fieldChanges')->where('tenant_id', $this->tenant->id)->where('action', 'TRIAL_EXTENDED')->sole();
    expect(periodsOf($this->tenant))->toBe([['trial', '2027-03-01', '2027-03-03', 'operator'], ['trial', '2027-03-04', '2027-03-10', 'operator']])
        ->and($extension->reason)->toBe('Asked for another week')
        ->and($extension->effective_date->toDateString())->toBe('2027-03-04')
        ->and($extension->metadata['until'])->toBe('2027-03-10');

    // Days pass: the trial ends; nothing converts it. The settlement records the expiry the dates decided.
    $this->travelTo('2027-03-11 00:15:00');
    $this->artisan('peopleos:subscriptions:settle')->assertSuccessful();
    expect(periodsOf($this->tenant))->toBe([['trial', '2027-03-01', '2027-03-03', 'operator'], ['trial', '2027-03-04', '2027-03-10', 'operator'], ['expired', '2027-03-11', null, 'scheduler']])
        ->and(periodsOf($other)[0][0])->toBe('trial');
});

it('settles idempotently, late, twice, and per tenant: the expiry is always the day after the end', function () {
    Event::fake([CommercialStatusChanged::class]);
    Mail::fake();
    $this->subs->startTrial($this->tenant, $this->growth, '2027-03-01', '2027-03-07', 'One-week trial', $this->operator);
    $beta = provisionTenant('Beta');
    $this->subs->start($beta, $this->growth, '2027-03-01', '2027-03-31', 'One-month term', $this->operator);

    // The scheduler was down for ten days.
    $this->travelTo('2027-03-18 09:00:00');
    $this->artisan('peopleos:subscriptions:settle')->assertSuccessful();
    $this->artisan('peopleos:subscriptions:settle')->assertSuccessful();
    $this->artisan('peopleos:subscriptions:settle', ['--tenant' => $this->tenant->slug])->assertSuccessful();

    expect(periodsOf($this->tenant))->toBe([['trial', '2027-03-01', '2027-03-07', 'operator'], ['expired', '2027-03-08', null, 'scheduler']])
        ->and(periodsOf($beta))->toBe([['active', '2027-03-01', '2027-03-31', 'operator']])   // not due yet
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'SUBSCRIPTION_EXPIRED')->whereNotNull('tenant_id')->count())->toBe(1);
    $expiry = AuditEvent::query()->withoutTenancy()->where('action', 'SUBSCRIPTION_EXPIRED')->where('tenant_id', $this->tenant->id)->sole();
    expect($expiry->actor_id)->toBeNull()
        ->and($expiry->effective_date->toDateString())->toBe('2027-03-08')
        ->and($expiry->metadata['trigger'])->toBe('scheduler')
        ->and($expiry->reason)->toBe('The trial ended on 2027-03-07 with no successor');
    Event::assertDispatchedTimes(CommercialStatusChanged::class, 3); // trial started, term started, trial expired
    Event::assertDispatched(CommercialStatusChanged::class, fn (CommercialStatusChanged $e) => $e->tenantId === $this->tenant->id && $e->to === 'expired' && $e->effectiveOn === '2027-03-08' && $e->trigger === 'scheduler');
    Mail::assertNothingSent();

    // The term of the other tenant ends on the 31st: settled on its own day, by the same command.
    $this->travelTo('2027-04-01 00:15:00');
    $this->artisan('peopleos:subscriptions:settle')->assertSuccessful();
    expect(periodsOf($beta))->toBe([['active', '2027-03-01', '2027-03-31', 'operator'], ['expired', '2027-04-01', null, 'scheduler']]);
});

it('settles suspended tenants too, without touching their technical status, and one tenant\'s failure never stops another', function () {
    $this->subs->startTrial($this->tenant, $this->growth, '2027-03-01', '2027-03-02', 'Short trial', $this->operator);
    $beta = provisionTenant('Beta');
    $this->subs->startTrial($beta, $this->growth, '2027-03-01', '2027-03-02', 'Short trial', $this->operator);
    app(TenantSuspensions::class)->suspend($this->tenant, 'Security investigation', $this->operator);

    $this->travelTo('2027-03-05 09:00:00');
    $this->artisan('peopleos:subscriptions:settle')->assertSuccessful();
    expect(periodsOf($this->tenant)[1][0])->toBe('expired')
        ->and(periodsOf($beta)[1][0])->toBe('expired')
        ->and($this->tenant->fresh()->status->value)->toBe('suspended')
        ->and($beta->fresh()->status->value)->toBe('active');
});

it('keeps a grace window and a fixed term as explicit as a trial: their lapse is recorded the same way', function () {
    $sub = $this->subs->start($this->tenant, $this->growth, '2027-03-01', null, 'Open-ended contract', $this->operator);
    $sub = $this->subs->enterGrace($sub, '2027-03-10', '2027-03-20', 'Renewal paperwork pending', $this->operator);
    $this->travelTo('2027-03-21 00:15:00');
    $this->artisan('peopleos:subscriptions:settle')->assertSuccessful();

    expect(periodsOf($this->tenant))->toBe([['active', '2027-03-01', '2027-03-09', 'operator'], ['grace', '2027-03-10', '2027-03-20', 'operator'], ['expired', '2027-03-21', null, 'scheduler']]);
    // Reactivation after the grace lapsed is an explicit, audited operator decision.
    $this->subs->reactivate($sub, '2027-03-21', null, 'Renewal finally signed', $this->operator);
    expect(periodsOf($this->tenant)[2])->toBe(['active', '2027-03-21', null, 'operator'])
        ->and(app(TenantContext::class)->runAs($this->tenant, fn () => SubscriptionPeriod::query()->where('trigger', 'scheduler')->sole()->voided_at))->not->toBeNull();
});

it('gives the same state on every day whichever comes first: the settlement, or a later change dated after the end', function () {
    $alpha = $this->subs->startTrial($this->tenant, $this->growth, '2027-03-01', '2027-03-05', 'Short trial', $this->operator);
    $beta = provisionTenant('Beta');
    $bSub = $this->subs->startTrial($beta, $this->growth, '2027-03-01', '2027-03-05', 'Short trial', $this->operator);
    $gamma = provisionTenant('Gamma');
    $gSub = $this->subs->startTrial($gamma, $this->growth, '2027-03-01', '2027-03-05', 'Short trial', $this->operator);
    $this->subs->cancel($gSub, '2027-03-10', 'Leaves some days after the trial', $this->operator); // recorded in advance

    $this->travelTo('2027-03-08 09:00:00');
    $this->subs->settle($this->tenant);                                                           // Alpha: settlement first
    $this->subs->reactivate($alpha, '2027-03-08', null, 'Late signature', $this->operator);
    $this->subs->reactivate($bSub, '2027-03-08', null, 'Late signature', $this->operator);         // Beta: reactivation first
    $this->subs->settle($beta);
    $this->subs->settle($gamma);

    $days = fn (TenantSubscription $sub) => app(TenantContext::class)->runAs(Tenant::query()->findOrFail($sub->tenant_id), fn () => collect(['2027-03-05', '2027-03-06', '2027-03-07', '2027-03-08', '2027-03-09', '2027-03-10'])
        ->mapWithKeys(fn (string $d) => [$d => TenantSubscription::query()->with('periods')->findOrFail($sub->id)->timeline()->stateOn($d)['status']->value])->all());
    expect($days($alpha))->toBe(['2027-03-05' => 'trial', '2027-03-06' => 'expired', '2027-03-07' => 'expired', '2027-03-08' => 'active', '2027-03-09' => 'active', '2027-03-10' => 'active'])
        ->and($days($bSub))->toBe($days($alpha))
        ->and($days($gSub))->toBe(['2027-03-05' => 'trial', '2027-03-06' => 'expired', '2027-03-07' => 'expired', '2027-03-08' => 'expired', '2027-03-09' => 'expired', '2027-03-10' => 'cancelled']);

    // The recorded history says what happened: Alpha's expiry was recorded by the settlement and closed by the
    // reactivation; Beta's was never recorded, and its reactivation is audited from the expired state it ended.
    expect(periodsOf($this->tenant)[1])->toBe(['expired', '2027-03-06', '2027-03-07', 'scheduler'])
        ->and(array_column(periodsOf($beta), 0))->toBe(['trial', 'active'])
        ->and(AuditEvent::query()->withoutTenancy()->with('fieldChanges')->where('tenant_id', $beta->id)->where('action', 'SUBSCRIPTION_REACTIVATED')->sole()
            ->fieldChanges->firstWhere('field', 'commercial_status')->only(['before', 'after']))->toBe(['before' => 'expired (lapsed)', 'after' => 'active']);
});
