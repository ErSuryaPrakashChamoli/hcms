<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Offboarding\Enums\OffboardingStatus;
use App\Domain\Offboarding\Services\TenantDeletions;
use App\Domain\Offboarding\Services\TenantOffboardings;
use App\Domain\Platform\Actions\ProvisionTenantAction;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Platform\Services\TenantSuspensions;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Support\Tenancy\Jobs\DeferredTenantJobs;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TenantAwareTestJob;

require_once __DIR__.'/OffboardingTestHelpers.php';
require_once __DIR__.'/../Entitlements/PlanTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| SaaS.9: the offboarding case. Request → close (the end of service) → reopen (until a deletion is planned or starts);
| every step by a platform operator, with a reason, audited, and never silently ending a subscription.
*/

beforeEach(function () {
    Storage::fake('local');
    [$this->maker, $this->checker] = exitOperators();
    $this->offboardings = app(TenantOffboardings::class);
    $this->tenant = provisionTenant('Leaving Co');
});

it('closes a tenant at the end of service: nobody signs in, API keys stop, the warehouse feed stops', function () {
    actAsTenant($this->tenant);
    $key = app(ApiKeys::class)->issue('Reader', ['employees.read'])['plaintext'];
    app(SettingsRepository::class)->set('warehouse.export_enabled', true, 'Feed on');
    $user = tenantUser($this->tenant, ['employee.view']);
    actAsTenant(null);

    $case = $this->offboardings->request($this->tenant, 'Customer gave notice', 'NOTICE-1', '2026-12-31', $this->maker);
    expect($case->status)->toBe(OffboardingStatus::Requested)->and($this->tenant->fresh()->status)->toBe(TenantStatus::Active)
        ->and(fn () => $this->offboardings->request($this->tenant, 'Twice', null, null, $this->maker))->toThrow(RuntimeException::class, 'already has an open offboarding');

    $case = $this->offboardings->close($case, $this->maker, 'Service ended');
    $tenant = $this->tenant->fresh();
    expect($tenant->status)->toBe(TenantStatus::Closed)->and($tenant->isAccessible())->toBeFalse()
        ->and($case->closed_from_status)->toBe('active')
        ->and(app(TenantContext::class)->runAs($tenant, fn () => app(SettingsRepository::class)->get('warehouse.export_enabled')))->toBeFalse();
    $this->withHeader('X-Api-Key', $key)->getJson('/api/v1/employees')->assertStatus(401);
    expect(AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', 'TENANT_CLOSED')->where('metadata->subject_tenant_id', $tenant->id)->count())->toBe(1)
        // A tenant's exit is not its employees' exit: no employee lifecycle changed.
        ->and(AuditEvent::query()->withoutTenancy()->where('tenant_id', $tenant->id)->where('action', 'LIFECYCLE_TRANSITION')->count())->toBe(0);
});

it('refuses to close while a subscription continues, and closes once it is ended through the subscription lifecycle', function () {
    $this->travelTo('2026-10-12 10:00:00');
    $plan = publishedPlan($this->maker, 'exitplan', ['leave' => true], '2026-10-12');
    $subscription = app(CommercialSubscriptions::class)->start($this->tenant, $plan, '2026-10-12', null, 'Contract', $this->maker);
    $case = $this->offboardings->request($this->tenant, 'Customer gave notice', null, null, $this->maker);

    expect(fn () => $this->offboardings->close($case, $this->maker, 'Service ended'))->toThrow(RuntimeException::class, 'end it on Subscriptions first')
        ->and($this->tenant->fresh()->status)->toBe(TenantStatus::Active);

    app(CommercialSubscriptions::class)->cancel($subscription, '2026-10-12', 'Contract ended', $this->maker);
    $this->offboardings->close($case, $this->maker, 'Service ended');
    expect($this->tenant->fresh()->status)->toBe(TenantStatus::Closed);
});

it('reopens to the status it had, restores the feed and releases the work queued meanwhile', function () {
    $this->tenant->forceFill(['status' => TenantStatus::Trial])->save();
    actAsTenant($this->tenant);
    app(SettingsRepository::class)->set('warehouse.export_enabled', true, 'Feed on');
    actAsTenant(null);
    $case = offboardAndClose($this->tenant, $this->maker);
    actAsTenant($this->tenant);
    dispatch(new TenantAwareTestJob('Queued while closed'));
    actAsTenant(null);
    expect(app(DeferredTenantJobs::class)->pending($this->tenant))->toBe(1);

    $this->offboardings->reopen($case, $this->maker, 'Customer renewed');

    expect($this->tenant->fresh()->status)->toBe(TenantStatus::Trial)
        ->and($case->fresh()->status)->toBe(OffboardingStatus::Cancelled)
        ->and(app(DeferredTenantJobs::class)->pending($this->tenant))->toBe(0)
        ->and(app(TenantContext::class)->runAs($this->tenant, fn () => app(SettingsRepository::class)->get('warehouse.export_enabled')))->toBeTrue();
});

it('refuses to reopen once a deletion is planned, and for good once one has started', function () {
    config(['peopleos.offboarding.deletion_enabled' => true]);
    retentionFor(null, 0, $this->maker, $this->checker);
    actAsTenant($this->tenant);
    employeeWithUser();
    actAsTenant(null);
    $case = offboardAndClose($this->tenant, $this->maker);
    $this->offboardings->waiveExport($case, $this->maker, 'Customer took a copy before leaving');
    $deletion = app(TenantDeletions::class)->request($case->fresh(), $this->maker, 'Delete');

    expect(fn () => $this->offboardings->reopen($case->fresh(), $this->maker, 'Changed mind'))->toThrow(RuntimeException::class, 'cancel or reject it before reopening');
    app(TenantDeletions::class)->cancel($deletion, $this->maker, 'Customer may come back');
    // Cancelled: reopening works again.
    $this->offboardings->reopen($case->fresh(), $this->maker, 'Changed mind');
    expect($this->tenant->fresh()->status)->toBe(TenantStatus::Active);

    // A deletion that started (even if it then stopped) means no reopening, ever.
    $case = offboardAndClose($this->tenant, $this->maker);
    $this->offboardings->waiveExport($case, $this->maker, 'Waived again');
    $deletion = approvedDeletion($case->fresh(), $this->maker, $this->checker);
    $this->travel(15)->days();
    app(TenantDeletions::class)->dispatchDue();
    expect(fn () => $this->offboardings->reopen($case->fresh(), $this->maker, 'Too late'))->toThrow(RuntimeException::class);
});

it('keeps closed and deleted for offboarding only: no suspension, no provisioning in those states', function () {
    $case = offboardAndClose($this->tenant, $this->maker);
    expect(app(TenantSuspensions::class)->suspend($this->tenant->fresh(), 'Abuse', $this->maker))->toBeFalse()
        ->and($this->tenant->fresh()->status)->toBe(TenantStatus::Closed)
        ->and(fn () => app(ProvisionTenantAction::class)->handle(['name' => 'Ghost', 'slug' => 'ghost', 'status' => 'closed']))->toThrow(InvalidArgumentException::class)
        ->and(Tenant::query()->where('slug', 'ghost')->exists())->toBeFalse();
});

it('lets only platform operators run the case, in the service', function () {
    actAsTenant($this->tenant);
    $tenantAdmin = tenantUser($this->tenant, ['*']);
    actAsTenant(null);
    expect(fn () => $this->offboardings->request($this->tenant, 'Leaving', null, null, $tenantAdmin))->toThrow(RuntimeException::class, 'Only platform operators');
    $case = $this->offboardings->request($this->tenant, 'Leaving', null, null, $this->maker);
    expect(fn () => $this->offboardings->close($case, $tenantAdmin, 'Close it'))->toThrow(RuntimeException::class, 'Only platform operators')
        ->and(fn () => $this->offboardings->close($case, $this->maker, 'no'))->toThrow(RuntimeException::class, 'A reason is required');
});
