<?php

use App\Domain\Audit\Exceptions\ImmutableAuditRecordException;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\TenantSuspensions;
use App\Domain\Subscriptions\Models\SubscriptionPeriod;
use App\Domain\Subscriptions\Models\TenantSubscription;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Filament\Pages\PlatformSubscriptionsPage;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;

require_once __DIR__.'/../Entitlements/PlanTestHelpers.php';

/*
| SaaS.6: commercial subscriptions are a platform operation. Only operators change them (in the service, not just on
| the page), always with a reason; tenants never see or touch another tenant's subscription; history and audit cannot
| be rewritten through the application; a technical suspension and the commercial lifecycle stay independent.
*/

beforeEach(function () {
    $this->travelTo('2027-03-01 09:00:00');
    $this->a = provisionTenant('Alpha');
    $this->b = provisionTenant('Beta');
    $this->operator = platformAdmin();
    $this->subs = app(CommercialSubscriptions::class);
    $this->growth = publishedPlan($this->operator, 'growth', ['leave' => true, 'payroll' => true], '2027-03-01');
    $this->bSub = $this->subs->startTrial($this->b, $this->growth, '2027-03-01', '2027-03-31', 'Beta trial', $this->operator);
    actAsTenant($this->a);
});

it('refuses every non-operator in the service, for any tenant and every operation', function () {
    $flagged = tenantUser($this->a, ['*']);
    $flagged->forceFill(['is_platform_admin' => true])->save();
    $people = [
        'employee' => tenantUser($this->a, ['leave.apply']),
        'manager' => tenantUser($this->a, ['leave.approve']),
        'tenant administrator' => tenantUser($this->a, ['*']),
        'tenant user flagged as operator' => $flagged,
        'tenantless non-operator' => User::factory()->create(['tenant_id' => null, 'is_platform_admin' => false]),
    ];
    foreach ($people as $who => $user) {
        $user = $user->fresh();
        foreach ([
            fn () => $this->subs->startTrial($this->a, $this->growth, '2027-03-01', '2027-03-31', 'Self-service trial', $user),
            fn () => $this->subs->start($this->a, $this->growth, '2027-03-01', null, 'Self-service start', $user),
            fn () => $this->subs->extend($this->bSub, '2027-12-31', 'Extend another tenant', $user),
            fn () => $this->subs->convert($this->bSub, '2027-03-05', null, 'Convert another tenant', $user),
            fn () => $this->subs->enterGrace($this->bSub, '2027-03-05', '2027-03-10', 'Grace for another', $user),
            fn () => $this->subs->reactivate($this->bSub, '2027-03-05', null, 'Reactivate another', $user),
            fn () => $this->subs->expire($this->bSub, '2027-03-05', 'Expire another', $user),
            fn () => $this->subs->cancel($this->bSub, '2027-03-05', 'Cancel another', $user),
            fn () => $this->subs->changePlan($this->bSub, $this->growth, '2027-03-05', 'Change another', $user),
        ] as $attempt) {
            expect($attempt)->toThrow(RuntimeException::class, 'Only platform operators');
        }
    }
    expect(fn () => $this->subs->cancel($this->bSub, '2027-03-05', 'no', $this->operator))->toThrow(RuntimeException::class, 'reason')
        ->and(fn () => $this->subs->cancel($this->bSub, '2027-03-05', str_repeat('Too long. ', 51), $this->operator))->toThrow(RuntimeException::class, '500 characters')
        ->and(fn () => $this->subs->cancel($this->bSub, '2027-03-05', 'Customer leaves', $this->operator, str_repeat('R', 101)))->toThrow(RuntimeException::class, 'reference to 100')
        ->and(app(TenantContext::class)->runAs($this->b, fn () => SubscriptionPeriod::query()->count()))->toBe(1);
});

it('keeps each tenant\'s subscriptions to itself and refuses the page to every tenant user', function () {
    expect(TenantSubscription::query()->count())->toBe(0)->and(SubscriptionPeriod::query()->count())->toBe(0)
        ->and(TenantSubscription::query()->find($this->bSub->id))->toBeNull();
    actAsTenant(null);
    expect(TenantSubscription::query()->count())->toBe(0); // fail-closed without a tenant

    foreach ([tenantUser($this->b, ['*']), tenantUser($this->b, ['leave.apply'])] as $user) {
        $this->actingAs($user);
        $this->get(PlatformSubscriptionsPage::getUrl(['tenant' => $this->b->id]))->assertForbidden();
    }
    expect(PlatformSubscriptionsPage::canAccess())->toBeFalse();
});

it('cannot rewrite history through the models: periods keep state, version and start; nothing is deleted', function () {
    $period = app(TenantContext::class)->runAs($this->b, fn () => SubscriptionPeriod::query()->sole());
    $subscription = app(TenantContext::class)->runAs($this->b, fn () => TenantSubscription::query()->sole());
    expect(fn () => $period->forceFill(['status' => 'active'])->save())->toThrow(RuntimeException::class, 'keeps its state')
        ->and(fn () => $period->fresh()->forceFill(['starts_on' => '2027-02-01'])->save())->toThrow(RuntimeException::class, 'keeps its state')
        ->and(fn () => $period->fresh()->forceFill(['ends_on' => '2027-12-31'])->save())->toThrow(RuntimeException::class, 'only end earlier')
        ->and(fn () => $period->fresh()->delete())->toThrow(RuntimeException::class, 'never deleted')
        ->and(fn () => $subscription->forceFill(['reason' => 'Rewritten'])->save())->toThrow(RuntimeException::class, 'never edited')
        ->and(fn () => $subscription->delete())->toThrow(RuntimeException::class, 'never deleted');
});

it('lets an operator run the lifecycle on the page, audited on both chains with before and after, never rewritable', function () {
    $this->actingAs($this->operator);
    actAsTenant(null);
    $this->get(PlatformSubscriptionsPage::getUrl())->assertOk()->assertSee('Alpha')->assertSee('Beta')->assertSee('trial');

    Livewire::test(PlatformSubscriptionsPage::class, ['tenant' => $this->a->id])
        ->assertActionVisible('startTrial')->assertActionHidden('convert')->assertActionHidden('cancel')
        ->callAction('startTrial', data: ['version' => $this->growth->id, 'from' => '2027-03-01', 'until' => '2027-03-20', 'reference' => 'DEAL-5', 'reason' => 'Alpha pilot'])
        ->assertHasNoActionErrors();
    Livewire::test(PlatformSubscriptionsPage::class, ['tenant' => $this->a->id])
        ->assertActionHidden('startTrial')->assertActionVisible('convert')->assertActionVisible('extend')->assertActionHidden('reactivate')
        ->callAction('convert', data: ['from' => '2027-03-01', 'reason' => 'Signed on day one'])
        ->assertHasNoActionErrors();
    Livewire::test(PlatformSubscriptionsPage::class, ['tenant' => $this->a->id])
        ->callAction('cancel', data: ['from' => '2027-03-01', 'reason' => 'no'])
        ->assertHasActionErrors(['reason']);

    $this->get(PlatformSubscriptionsPage::getUrl(['tenant' => $this->a->id]))->assertOk()
        ->assertSee('TRIAL_CONVERTED')->assertSee('trial → active')->assertSee('Signed on day one')->assertSee('DEAL-5')->assertSee('voided');
    $events = AuditEvent::query()->withoutTenancy()->where('module', 'subscriptions')->whereIn('action', ['TRIAL_STARTED', 'TRIAL_CONVERTED'])->get();
    expect($events->where('tenant_id', $this->a->id)->pluck('action')->map->value->sort()->values()->all())->toBe(['TRIAL_CONVERTED', 'TRIAL_STARTED'])
        ->and($events->whereNull('tenant_id')->filter(fn ($e) => ($e->metadata['subject_tenant_id'] ?? null) === $this->a->id)->count())->toBe(2)
        ->and($events->every(fn ($e) => $e->actor_id === $this->operator->id && filled($e->reason) && $e->effective_date !== null))->toBeTrue()
        ->and(fn () => $events->first()->update(['reason' => 'rewritten']))->toThrow(ImmutableAuditRecordException::class)
        ->and(app(AuditIntegrityVerifier::class)->verify($this->a->id)['valid'])->toBeTrue()
        ->and(app(AuditIntegrityVerifier::class)->verify(null)['valid'])->toBeTrue();
});

it('keeps technical suspension and the commercial lifecycle independent', function () {
    app(TenantSuspensions::class)->suspend($this->b, 'Abuse investigation', $this->operator);
    $converted = $this->subs->convert($this->bSub, '2027-03-05', null, 'Contract signed during the investigation', $this->operator);
    expect($this->b->fresh()->status->value)->toBe('suspended')
        ->and(app(TenantContext::class)->runAs($this->b, fn () => $converted->fresh('periods')->timeline()->stateOn('2027-03-05')['status']->value))->toBe('active');

    app(TenantSuspensions::class)->reactivate($this->b, 'Investigation closed', $this->operator);
    $this->subs->cancel($this->bSub, '2027-03-10', 'Customer leaves', $this->operator);
    expect($this->b->fresh()->status->value)->toBe('active');  // a commercial cancellation never suspends the tenant
});
