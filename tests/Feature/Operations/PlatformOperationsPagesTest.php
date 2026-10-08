<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Platform\Services\PlatformOperationsOverview;
use App\Domain\Platform\Services\TenantDeferredWork;
use App\Domain\Platform\Services\TenantSuspensions;
use App\Filament\Pages\PlatformAuditTrailPage;
use App\Filament\Pages\PlatformOperationsPage;
use App\Support\Tenancy\Jobs\DeferredTenantJobs;
use Livewire\Livewire;
use Tests\Support\TenantAwareTestJob;

/*
| SaaS.8 (WS8 core): the operations page and the platform audit trail. Platform operators only; counts and states, never
| tenant records or message content; the platform chain only.
*/

beforeEach(function () {
    $this->operator = platformAdmin();
    $this->acme = provisionTenant('Acme');
    $this->calm = provisionTenant('Calm');
    actAsTenant($this->acme);
    $this->acmeAdmin = tenantUser($this->acme, ['*']);
    $user = tenantUser($this->acme, ['task.view']);
    NotificationDelivery::query()->create(['user_id' => $user->id, 'channel' => 'email', 'subject' => 'Confidential appraisal outcome', 'body' => 'Rating 2 of 5',
        'status' => 'sending', 'recipient' => 'person@acme.test']);
    NotificationDelivery::query()->where('status', 'sending')->update(['updated_at' => now()->subHour()]);
    WebhookEndpoint::query()->create(['name' => 'ERP', 'url' => 'https://erp.example.test/hook', 'secret' => 'not-shown-secret', 'events' => ['employee.created'], 'failure_count' => 3]);
    actAsTenant(null);
    $this->suspensions = app(TenantSuspensions::class);
});

it('is for platform operators only', function () {
    foreach ([PlatformOperationsPage::getUrl(), PlatformAuditTrailPage::getUrl()] as $url) {
        $this->actingAs($this->acmeAdmin)->get($url)->assertForbidden();
        $this->actingAs($this->operator)->get($url)->assertOk();
    }
});

it('shows each tenant\'s operational counts, tenants needing attention first, and no record or message content', function () {
    $rows = app(PlatformOperationsOverview::class)->tenants();
    expect($rows[0]['tenant']->id)->toBe($this->acme->id)
        ->and($rows[0])->toMatchArray(['stuck_notifications' => 1, 'failing_webhooks' => 1, 'deferred' => 0, 'attention' => true])
        ->and(collect($rows)->firstWhere('tenant.id', $this->calm->id)['attention'])->toBeFalse();

    $this->actingAs($this->operator)->get(PlatformOperationsPage::getUrl())->assertOk()
        ->assertSee('Acme')->assertSee('Calm')
        ->assertDontSee('Confidential appraisal outcome')->assertDontSee('person@acme.test')->assertDontSee('not-shown-secret')->assertDontSee('erp.example.test');
});

it('releases a reactivated tenant\'s deferred work from the page, with a reason, audited on both chains', function () {
    $this->suspensions->suspend($this->acme, 'Investigation', $this->operator);
    actAsTenant($this->acme);
    dispatch(new TenantAwareTestJob('Deferred company'));
    actAsTenant(null);
    // The automatic release on reactivation did not happen (simulated: the status changed outside the service).
    $this->acme->forceFill(['status' => 'active'])->save();
    expect(app(DeferredTenantJobs::class)->pending($this->acme))->toBe(1);

    $this->actingAs($this->operator);
    Livewire::test(PlatformOperationsPage::class)->set('tenant', $this->acme->id)
        ->assertActionVisible('releaseDeferred')
        ->callAction('releaseDeferred', data: ['reason' => 'Release after outage'])->assertNotified('1 job(s) dispatched again');

    expect(app(DeferredTenantJobs::class)->pending($this->acme))->toBe(0);
    $events = AuditEvent::query()->withoutTenancy()->where('action', 'DEFERRED_WORK_RELEASED')->get();
    expect($events->pluck('tenant_id')->sort()->values()->all())->toBe([null, $this->acme->id])
        ->and($events->every(fn ($e) => $e->metadata['released'] === 1 && $e->actor_id === $this->operator->id))->toBeTrue();
    Livewire::test(PlatformOperationsPage::class)->set('tenant', $this->acme->id)->assertActionHidden('releaseDeferred');
});

it('refuses the release to a tenant user and while the tenant is suspended, in the service', function () {
    $work = app(TenantDeferredWork::class);
    expect(fn () => $work->release($this->acme, $this->acmeAdmin, 'Let my jobs run'))->toThrow(RuntimeException::class, 'Only platform operators');

    $this->suspensions->suspend($this->acme, 'Investigation', $this->operator);
    expect(fn () => $work->release($this->acme->fresh(), $this->operator, 'Too early'))->toThrow(RuntimeException::class, 'reactivate it first');
});

it('lists the platform chain only, filtered by action and tenant, with the before and after values', function () {
    $this->suspensions->suspend($this->acme, 'Platform-visible reason', $this->operator);
    $this->suspensions->suspend($this->calm, 'Calm reason', $this->operator);
    actAsTenant($this->acme);
    app(AuditRecorder::class)->record(AuditAction::SettingChanged, 'platform', null, [], 'Tenant-only reason never shown here');
    actAsTenant(null);
    $this->actingAs($this->operator);

    $this->get(PlatformAuditTrailPage::getUrl())->assertOk()->assertSee('Platform-visible reason')->assertSee('Calm reason')->assertDontSee('Tenant-only reason never shown here');

    $event = AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', 'TENANT_SUSPENDED')->where('metadata->subject_tenant_id', $this->acme->id)->sole();
    Livewire::test(PlatformAuditTrailPage::class)->set('action', 'TENANT_SUSPENDED')->set('subject', $this->acme->id)
        ->assertSee('Platform-visible reason')->assertDontSee('Calm reason')
        ->set('event', $event->id)->assertSee('suspended');

    // A tenant chain event cannot be opened here, even by id.
    $tenantEvent = AuditEvent::query()->withoutTenancy()->where('tenant_id', $this->acme->id)->latest('occurred_at')->first();
    Livewire::test(PlatformAuditTrailPage::class)->set('event', $tenantEvent->id)->assertDontSee('Tenant-only reason never shown here');
});
