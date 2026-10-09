<?php

use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Offboarding\Enums\DeletionStatus;
use App\Domain\Offboarding\Models\TenantDeletionStep;
use App\Domain\Offboarding\Services\DeletionExecutor;
use App\Domain\Offboarding\Services\DeletionPlanner;
use App\Domain\Offboarding\Services\LegalHolds;
use App\Domain\Offboarding\Services\TenantDeletions;
use App\Domain\Offboarding\Services\TenantOffboardings;
use App\Domain\Offboarding\Support\Signatures;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use Database\Seeders\UxShowcaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/OffboardingTestHelpers.php';
require_once __DIR__.'/../Billing/BillingTestHelpers.php';

/*
| SaaS.9: the destructive path fails safe. Every gate, the environment switch, maker-checker, an approval bound to an
| unchanged plan, legal holds before and during the run, and a run that dies halfway and resumes without repeating
| finished steps or deleting what became protected.
*/

beforeEach(function () {
    Storage::fake('local');
    config(['peopleos.offboarding.deletion_enabled' => true]);
    $this->seed(UxShowcaseSeeder::class);
    $this->tenant = Tenant::query()->where('slug', 'demo')->firstOrFail();
    [$this->maker, $this->checker] = exitOperators();
    $this->deletions = app(TenantDeletions::class);
    retentionFor(null, 0, $this->maker, $this->checker);
});

it('is not deletable before closure, without a delivered or waived export, or with an open financial item', function () {
    $offboardings = app(TenantOffboardings::class);
    $case = $offboardings->request($this->tenant, 'Notice', null, null, $this->maker);
    expect(collect(app(DeletionPlanner::class)->plan($this->tenant, $case)['gates'])->pluck('code'))->toContain('TENANT_NOT_CLOSED');
    $case = $offboardings->close($case, $this->maker, 'Service ended');
    $codes = fn () => collect(app(DeletionPlanner::class)->plan($this->tenant->fresh(), $case->fresh())['gates'])->pluck('code')->all();
    expect($codes())->toBe(['EXPORT_NOT_DELIVERED'])
        ->and(fn () => $this->deletions->request($case->fresh(), $this->maker, 'Delete now'))->toThrow(RuntimeException::class, 'No export has been delivered');

    $offboardings->waiveExport($case, $this->maker, 'Customer took their own copy');
    $billing = indiaBilling();
    billingProfile($this->tenant, $billing['market'], $billing['operator']);
    draftInvoice($this->tenant, $billing['market'], $billing['operator']);
    expect($codes())->toBe(['FINANCIAL_DRAFT_INVOICES'])
        ->and(fn () => $this->deletions->request($case->fresh(), $this->maker, 'Delete now'))->toThrow(RuntimeException::class, 'draft invoice');
});

it('does nothing while deletion is switched off for the environment', function () {
    $case = offboardAndClose($this->tenant, $this->maker);
    app(TenantOffboardings::class)->waiveExport($case, $this->maker, 'Waived for the test');
    $deletion = approvedDeletion($case->fresh(), $this->maker, $this->checker);
    config(['peopleos.offboarding.deletion_enabled' => false]);
    $this->travel(15)->days();

    expect($this->deletions->dispatchDue())->toBe(['dispatched' => 0, 'stalled' => 0])
        ->and(app(DeletionExecutor::class)->execute($deletion->id))->toBeFalse()
        ->and(DB::table('employees')->where('tenant_id', $this->tenant->id)->count())->toBeGreaterThan(0)
        ->and(fn () => $this->deletions->request($case->fresh(), $this->maker, 'Again'))->toThrow(RuntimeException::class, 'switched off');
});

it('needs a second operator, and only for the plan as it was made', function () {
    $case = offboardAndClose($this->tenant, $this->maker);
    app(TenantOffboardings::class)->waiveExport($case, $this->maker, 'Waived for the test');
    $deletion = $this->deletions->request($case->fresh(), $this->maker, 'Retention ended');

    expect(fn () => $this->deletions->approve($deletion, $this->maker, 'Approving my own'))->toThrow(RuntimeException::class, 'other than the one who planned it')
        ->and(fn () => $this->deletions->request($case->fresh(), $this->checker, 'A second plan'))->toThrow(RuntimeException::class, 'is already planned, approved, running or failed');

    // The data changes after the plan (a hold now keeps payroll): the plan no longer holds, and the approval is refused.
    app(LegalHolds::class)->place($this->tenant, ['payroll'], 'Audit by the regulator (fictional)', null, null, $this->maker);
    expect(fn () => $this->deletions->approve($deletion, $this->checker, 'Looks fine'))->toThrow(RuntimeException::class, 'changed since this plan was made')
        ->and($this->deletions->find($deletion->id)->status)->toBe(DeletionStatus::Planned);
    $this->deletions->reject($deletion, $this->checker, 'Plan again around the hold');
    expect($this->deletions->find($deletion->id)->status)->toBe(DeletionStatus::Rejected);
});

it('keeps what a hold placed after the approval covers, and everything that still depends on it', function () {
    $case = offboardAndClose($this->tenant, $this->maker);
    app(TenantOffboardings::class)->waiveExport($case, $this->maker, 'Waived for the test');
    $deletion = approvedDeletion($case->fresh(), $this->maker, $this->checker);
    $payroll = DB::table('payroll_entries')->where('tenant_id', $this->tenant->id)->count();
    $employees = DB::table('employees')->where('tenant_id', $this->tenant->id)->count();
    expect($payroll)->toBeGreaterThan(0);

    app(LegalHolds::class)->place($this->tenant, ['payroll'], 'Hold arrived during the cooling-off', null, null, $this->maker);
    $this->travel(15)->days();
    $this->deletions->dispatchDue();

    $deletion = $this->deletions->find($deletion->id);
    expect($deletion->status)->toBe(DeletionStatus::Completed, (string) $deletion->failure)
        ->and(DB::table('payroll_entries')->where('tenant_id', $this->tenant->id)->count())->toBe($payroll)
        ->and(DB::table('employees')->where('tenant_id', $this->tenant->id)->count())->toBe($employees)
        ->and(DB::table('leave_requests')->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and(TenantDeletionStep::query()->withoutTenancy()->where('tenant_deletion_id', $deletion->id)->where('category', 'payroll')->pluck('status')->unique()->all())->toBe(['skipped'])
        ->and(collect($deletion->certificate['categories']['retained'])->firstWhere('category', 'payroll')['reason'])->toBe('LEGAL_HOLD')
        ->and($this->tenant->fresh()->status)->toBe(TenantStatus::Closed);
});

it('stops before the next batch when a hold arrives during the run, and a retry resumes around it', function () {
    config(['peopleos.offboarding.batch_size' => 50]);
    $case = offboardAndClose($this->tenant, $this->maker);
    app(TenantOffboardings::class)->waiveExport($case, $this->maker, 'Waived for the test');
    $deletion = approvedDeletion($case->fresh(), $this->maker, $this->checker);
    $tenantId = $this->tenant->id;
    $placed = false;
    // The hold is written the moment the first audit rows are deleted, as another operator would (its own row).
    Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$placed, $tenantId) {
        if (! $placed && str_starts_with($q->sql, 'delete from "audit_events"')) {
            $placed = true;
            DB::table('legal_holds')->insert(['tenant_id' => $tenantId, 'reference' => (string) Str::ulid(), 'status' => 'active', 'scope' => 'categories',
                'categories' => json_encode(['audit']), 'reason' => 'Hold during the run', 'starts_on' => today()->toDateString(), 'placed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    });
    $this->travel(15)->days();
    $this->deletions->dispatchDue();

    $deletion = $this->deletions->find($deletion->id);
    $kept = DB::table('audit_events')->where('tenant_id', $tenantId)->pluck('id')->all();
    // Stopped mid-chain: what remains of the chain is still a valid prefix.
    expect(app(AuditIntegrityVerifier::class)->verify($tenantId)['valid'])->toBeTrue();
    expect($deletion->status)->toBe(DeletionStatus::Failed)
        ->and($deletion->failure)->toContain('legal hold')
        ->and($kept)->not->toBeEmpty();
    // Reopening is refused for good: a deletion started.
    expect(fn () => app(TenantOffboardings::class)->reopen($case->fresh(), $this->maker, 'Come back'))->toThrow(RuntimeException::class);

    // The retry re-plans: audit is held, so its steps are skipped and nothing more of it is deleted.
    $this->deletions->retry($deletion, $this->maker, 'Resume around the hold');
    $deletion = $this->deletions->find($deletion->id);
    expect($deletion->status)->toBe(DeletionStatus::Completed, (string) $deletion->failure)
        // Every audit event that survived the stop still exists (the retry only adds its own events to the chain).
        ->and(DB::table('audit_events')->whereIn('id', $kept)->count())->toBe(count($kept))
        ->and(app(AuditIntegrityVerifier::class)->verify($tenantId)['valid'])->toBeTrue();
});

it('resumes a run that died halfway without repeating finished steps', function () {
    config(['peopleos.offboarding.batch_size' => 50]);
    $case = offboardAndClose($this->tenant, $this->maker);
    app(TenantOffboardings::class)->waiveExport($case, $this->maker, 'Waived for the test');
    $deletion = approvedDeletion($case->fresh(), $this->maker, $this->checker);
    $crash = true;
    Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$crash) {
        if ($crash && str_starts_with($q->sql, 'delete from "leave_requests"')) {
            $crash = false;
            throw new RuntimeException('database went away (simulated)');
        }
    });
    $this->travel(15)->days();
    $this->deletions->dispatchDue();

    $deletion = $this->deletions->find($deletion->id);
    expect($deletion->status)->toBe(DeletionStatus::Failed)->and($deletion->failure)->toContain('database went away');
    $done = TenantDeletionStep::query()->withoutTenancy()->where('tenant_deletion_id', $deletion->id)->where('status', 'done')->pluck('completed_at', 'id');
    expect($done)->not->toBeEmpty();

    $this->deletions->retry($deletion, $this->maker, 'Database is back');
    $deletion = $this->deletions->find($deletion->id);
    expect($deletion->status)->toBe(DeletionStatus::Completed, (string) $deletion->failure)
        ->and($deletion->attempts)->toBe(2)
        ->and(LeaveRequest::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
    // Finished steps were not run again.
    foreach ($done as $id => $completedAt) {
        expect((string) TenantDeletionStep::query()->withoutTenancy()->find($id)->completed_at)->toBe((string) $completedAt);
    }
});

it('records external dependencies without claiming them, and a tampered certificate fails verification', function () {
    $case = offboardAndClose($this->tenant, $this->maker);
    app(TenantOffboardings::class)->waiveExport($case, $this->maker, 'Waived for the test');
    $deletion = approvedDeletion($case->fresh(), $this->maker, $this->checker);
    $this->travel(15)->days();
    $this->deletions->dispatchDue();
    $deletion = $this->deletions->find($deletion->id);

    $backup = TenantDeletionStep::query()->withoutTenancy()->where('tenant_deletion_id', $deletion->id)->where('target', 'database backups')->firstOrFail();
    expect($backup->status)->toBe(TenantDeletionStep::EXTERNAL_REQUIRED);
    $this->deletions->recordExternal($backup, TenantDeletionStep::EXTERNAL_CONFIRMED, $this->checker, 'Backups before 2026-10-27 expired (ticket OPS-1)');
    expect($backup->fresh()->status)->toBe(TenantDeletionStep::EXTERNAL_CONFIRMED);

    $certificate = $deletion->certificate;
    expect(Signatures::verify('deletion-certificate', $certificate, $deletion->certificate_signature))->toBeTrue();
    $certificate['categories']['deleted'] = [];
    expect(Signatures::verify('deletion-certificate', $certificate, $deletion->certificate_signature))->toBeFalse();
});
