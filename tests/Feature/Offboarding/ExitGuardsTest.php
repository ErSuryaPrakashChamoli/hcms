<?php

use App\Domain\Offboarding\Enums\DeletionStatus;
use App\Domain\Offboarding\Models\TenantDeletion;
use App\Domain\Offboarding\Models\TenantDeletionStep;
use App\Domain\Offboarding\Models\TenantExport;
use App\Domain\Offboarding\Services\DeletionExecutor;
use App\Domain\Offboarding\Services\DeletionPlanner;
use App\Domain\Offboarding\Services\LegalHolds;
use App\Domain\Offboarding\Services\TenantDeletions;
use App\Domain\Offboarding\Services\TenantExports;
use App\Domain\Offboarding\Services\TenantOffboardings;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/OffboardingTestHelpers.php';
require_once __DIR__.'/../Entitlements/PlanTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| SaaS.9: the guards that serialise the exit's transitions with each other (each re-checked under the exit lock): an
| export and a deletion never overlap, a hold placed after planning stops the approval, a closed tenant takes no new
| subscription, and an external confirmation is recorded once and kept.
*/

beforeEach(function () {
    Storage::fake('local');
    config(['peopleos.offboarding.deletion_enabled' => true]);
    [$this->maker, $this->checker] = exitOperators();
    $this->deletions = app(TenantDeletions::class);
    $this->tenant = provisionTenant('Guarded Co');
    actAsTenant($this->tenant);
    employeeWithUser();
    actAsTenant(null);
    retentionFor(null, 0, $this->maker, $this->checker);
});

it('never lets an export and a deletion overlap: an export in flight blocks the deletion, an approved deletion blocks exports', function () {
    $case = offboardAndClose($this->tenant, $this->maker);
    app(TenantOffboardings::class)->waiveExport($case, $this->maker, 'Customer took a copy');
    Queue::fake();
    $export = app(TenantExports::class)->request($this->tenant, $this->maker, 'One more copy', $case->fresh());

    // Generating: no plan, no approval.
    expect(collect(app(DeletionPlanner::class)->plan($this->tenant->fresh(), $case->fresh())['gates'])->pluck('code')->all())->toBe(['EXPORT_IN_PROGRESS'])
        ->and(fn () => $this->deletions->request($case->fresh(), $this->maker, 'Delete'))->toThrow(RuntimeException::class, 'export is being generated');
    app(TenantExports::class)->generate($export->id);

    $deletion = approvedDeletion($case->fresh(), $this->maker, $this->checker);
    expect(fn () => app(TenantExports::class)->request($this->tenant, $this->maker, 'Another copy', $case->fresh()))
        ->toThrow(RuntimeException::class, 'no new export');

    // An export queued before the approval (directly, as a racing request would have) stops the worker before it deletes.
    TenantExport::query()->withoutTenancy()->whereKey($export->id)->update(['status' => 'queued', 'in_flight_key' => $this->tenant->id]);
    $this->travel(15)->days();
    app(DeletionExecutor::class)->execute($deletion->id);
    $deletion = $this->deletions->find($deletion->id);
    expect($deletion->status)->toBe(DeletionStatus::Failed)
        ->and($deletion->failure)->toContain('export of this tenant is being generated')
        ->and($deletion->started_at)->toBeNull()
        ->and($case->fresh()->deletion_started_at)->toBeNull();
});

it('refuses the approval when a hold placed after the plan changes it, and allows it when the hold covers nothing to delete', function () {
    $case = offboardAndClose($this->tenant, $this->maker);
    app(TenantOffboardings::class)->waiveExport($case, $this->maker, 'Customer took a copy');
    $deletion = $this->deletions->request($case->fresh(), $this->maker, 'Delete');
    $hold = app(LegalHolds::class)->place($this->tenant, ['people'], 'A matter about people records', null, null, $this->maker);

    expect(fn () => $this->deletions->approve($deletion, $this->checker, 'Approve'))->toThrow(RuntimeException::class, 'changed since this plan was made')
        ->and($this->deletions->find($deletion->id)->status)->toBe(DeletionStatus::Planned);

    app(LegalHolds::class)->approveRelease(app(LegalHolds::class)->requestRelease($hold, $this->maker, 'Matter closed'), $this->checker, 'Confirmed closed');
    $empty = collect($deletion->plan['categories'])->filter(fn (array $c) => $c['reason'] === 'NOTHING_TO_DELETE')->keys()->first();
    app(LegalHolds::class)->place($this->tenant, [$empty], 'A matter on an empty category', null, null, $this->maker);
    expect($this->deletions->approve($deletion, $this->checker, 'Approve')->status)->toBe(DeletionStatus::Approved);
});

it('gives a closed tenant no new subscription, while ending one still works', function () {
    $this->travelTo('2026-10-12 10:00:00');
    $plan = publishedPlan($this->maker, 'closedplan', ['leave' => true], '2026-10-12');
    offboardAndClose($this->tenant, $this->maker);

    expect(fn () => app(CommercialSubscriptions::class)->start($this->tenant->fresh(), $plan, '2026-10-13', null, 'Restart', $this->maker))
        ->toThrow(RuntimeException::class, 'reopen it before it is entitled again');
});

it('records an external confirmation once and keeps it; nothing is recorded for a cancelled deletion', function () {
    $case = offboardAndClose($this->tenant, $this->maker);
    app(TenantOffboardings::class)->waiveExport($case, $this->maker, 'Customer took a copy');
    $deletion = approvedDeletion($case->fresh(), $this->maker, $this->checker);
    $backup = TenantDeletionStep::query()->withoutTenancy()->where('tenant_deletion_id', $deletion->id)->where('target', 'database backups')->firstOrFail();

    $this->deletions->recordExternal($backup, TenantDeletionStep::EXTERNAL_REQUESTED, $this->maker, 'Asked the backup provider');
    $this->deletions->recordExternal($backup->fresh(), TenantDeletionStep::EXTERNAL_CONFIRMED, $this->checker, 'Provider confirmed expiry');
    expect(fn () => $this->deletions->recordExternal($backup->fresh(), TenantDeletionStep::EXTERNAL_REQUESTED, $this->maker, 'Undo it'))
        ->toThrow(RuntimeException::class, 'already confirmed')
        ->and($backup->fresh()->status)->toBe(TenantDeletionStep::EXTERNAL_CONFIRMED)
        ->and($backup->fresh()->detail['previous_status'])->toBe(TenantDeletionStep::EXTERNAL_REQUESTED);

    $this->deletions->cancel($deletion, $this->maker, 'Customer may come back');
    $integration = TenantDeletionStep::query()->withoutTenancy()->where('tenant_deletion_id', $deletion->id)->where('kind', 'external')
        ->where('status', '!=', TenantDeletionStep::EXTERNAL_CONFIRMED)->firstOrFail();
    expect(fn () => $this->deletions->recordExternal($integration, TenantDeletionStep::EXTERNAL_CONFIRMED, $this->checker, 'Late record'))
        ->toThrow(RuntimeException::class, 'was not approved, or was cancelled');
});

it('stops safely when storage refuses a delete, claims nothing, and completes on an authorised retry', function () {
    $disk = Storage::disk('local');
    $disk->put("tenants/{$this->tenant->id}/employees/1/contract.pdf", 'fictional document');
    $case = offboardAndClose($this->tenant, $this->maker);
    app(TenantOffboardings::class)->waiveExport($case, $this->maker, 'Customer took a copy');
    $deletion = approvedDeletion($case->fresh(), $this->maker, $this->checker);

    $failing = Mockery::mock($disk)->makePartial();
    $failing->shouldReceive('delete')->andReturnFalse();
    Storage::set('local', $failing);
    $this->travel(15)->days();
    app(DeletionExecutor::class)->execute($deletion->id);

    $deletion = $this->deletions->find($deletion->id);
    expect($deletion->status)->toBe(DeletionStatus::Failed)
        ->and($deletion->failure)->toContain('could not be deleted')
        ->and($deletion->certificate)->toBeNull()
        ->and($this->tenant->fresh()->status->value)->toBe('closed')
        ->and($disk->exists("tenants/{$this->tenant->id}/employees/1/contract.pdf"))->toBeTrue();

    Storage::set('local', $disk);
    Queue::fake();
    $this->deletions->retry($deletion, $this->checker, 'Storage permissions fixed');
    app(DeletionExecutor::class)->execute($deletion->id);
    $deletion = $this->deletions->find($deletion->id);
    expect($deletion->status)->toBe(DeletionStatus::Completed, (string) $deletion->failure)
        ->and($disk->exists("tenants/{$this->tenant->id}/employees/1/contract.pdf"))->toBeFalse()
        ->and($deletion->certificate['final'])->toBeTrue();
});

it('records an export that storage refuses to write as failed, with no package, and a retry produces it', function () {
    Queue::fake();
    $disk = Storage::disk('local');
    $failing = Mockery::mock($disk)->makePartial();
    $failing->shouldReceive('writeStream')->andReturnFalse();
    Storage::set('local', $failing);
    $export = app(TenantExports::class)->request($this->tenant, $this->maker, 'Customer copy');
    app(TenantExports::class)->generate($export->id);

    $export = app(TenantExports::class)->find($export->id);
    expect($export->status->value)->toBe('failed')
        ->and($export->failure)->toContain('could not be written')
        ->and($export->path)->toBeNull()
        ->and($export->in_flight_key)->toBeNull();

    Storage::set('local', $disk);
    app(TenantExports::class)->generate(app(TenantExports::class)->retry($export, $this->maker, 'Disk space freed')->id);
    $export = app(TenantExports::class)->find($export->id);
    expect($export->status->value)->toBe('ready')->and($disk->exists($export->path))->toBeTrue();
});

it('never deletes a category without an approved retention policy, and plans nothing when none has one', function () {
    noRetentionPolicies();
    retentionFor(['leave'], 0, $this->maker, $this->checker);
    $case = offboardAndClose($this->tenant, $this->maker);
    app(TenantOffboardings::class)->waiveExport($case, $this->maker, 'Customer took a copy');
    $plan = app(DeletionPlanner::class)->plan($this->tenant->fresh(), $case->fresh());
    expect($plan['categories']['people']['reason'])->toBe('RETENTION_POLICY_NOT_CONFIGURED')
        ->and($plan['categories']['people']['action'])->toBe('retain')
        ->and($plan['eligible'])->not->toContain('people')->not->toContain('identity')->not->toContain('employment');

    noRetentionPolicies();
    expect(fn () => $this->deletions->request($case->fresh(), $this->maker, 'Delete'))->toThrow(RuntimeException::class, 'No category is eligible');
});

it('waits for the cooling-off after approval: nothing runs before it ends', function () {
    $case = offboardAndClose($this->tenant, $this->maker);
    app(TenantOffboardings::class)->waiveExport($case, $this->maker, 'Customer took a copy');
    $deletion = approvedDeletion($case->fresh(), $this->maker, $this->checker);
    expect($deletion->scheduled_for->toDateString())->toBe(now()->addDays(14)->toDateString());

    $this->deletions->dispatchDue();
    app(DeletionExecutor::class)->execute($deletion->id);
    $this->travel(13)->days();
    $this->deletions->dispatchDue();
    app(DeletionExecutor::class)->execute($deletion->id);
    expect($this->deletions->find($deletion->id)->status)->toBe(DeletionStatus::Approved)
        ->and($case->fresh()->deletion_started_at)->toBeNull();

    $this->travel(2)->days();
    $this->deletions->dispatchDue();
    expect($this->deletions->find($deletion->id)->status)->toBe(DeletionStatus::Completed);
});

it('lets no tenant user plan, approve, cancel or retry a deletion, or record an external copy', function () {
    $case = offboardAndClose($this->tenant, $this->maker);
    app(TenantOffboardings::class)->waiveExport($case, $this->maker, 'Customer took a copy');
    $admin = app(TenantContext::class)->runAs($this->tenant, fn () => tenantUser($this->tenant, ['*']));

    expect(fn () => $this->deletions->request($case->fresh(), $admin, 'Delete our data'))->toThrow(RuntimeException::class, 'Only platform operators')
        ->and(TenantDeletion::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
    $deletion = $this->deletions->request($case->fresh(), $this->maker, 'Delete');
    expect(fn () => $this->deletions->approve($deletion, $admin, 'Approve it'))->toThrow(RuntimeException::class, 'Only platform operators')
        ->and(fn () => $this->deletions->cancel($deletion, $admin, 'Cancel it'))->toThrow(RuntimeException::class, 'Only platform operators')
        ->and(fn () => $this->deletions->reject($deletion, $admin, 'Reject it'))->toThrow(RuntimeException::class, 'Only platform operators');

    $deletion = $this->deletions->approve($deletion, $this->checker, 'Plan reviewed');
    $backup = TenantDeletionStep::query()->withoutTenancy()->where('tenant_deletion_id', $deletion->id)->where('target', 'database backups')->firstOrFail();
    expect(fn () => $this->deletions->recordExternal($backup, TenantDeletionStep::EXTERNAL_CONFIRMED, $admin, 'Our backups are gone'))->toThrow(RuntimeException::class, 'Only platform operators')
        ->and(fn () => $this->deletions->retry($deletion, $admin, 'Run it again'))->toThrow(RuntimeException::class, 'Only platform operators')
        ->and($backup->fresh()->status)->toBe(TenantDeletionStep::EXTERNAL_REQUIRED);
});
