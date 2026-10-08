<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Models\Employee;
use App\Domain\Offboarding\Enums\DeletionStatus;
use App\Domain\Offboarding\Models\TenantDeletionStep;
use App\Domain\Offboarding\Services\DeletionExecutor;
use App\Domain\Offboarding\Services\DeletionPlanner;
use App\Domain\Offboarding\Services\TenantDeletions;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Offboarding\Support\Signatures;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\UxShowcaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/OffboardingTestHelpers.php';
require_once __DIR__.'/../Billing/BillingTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| SaaS.9: deleting a closed tenant's data. The showcase tenant (fictional people across every module) leaves; a second
| tenant stays. Nothing but the eligible categories disappears, Markedge's records and the exit's evidence remain, and the
| other tenant is untouched to the row and the file.
*/

beforeEach(function () {
    Storage::fake('local');
    config(['peopleos.offboarding.deletion_enabled' => true]);
    $this->seed(UxShowcaseSeeder::class);
    $this->tenant = Tenant::query()->where('slug', 'demo')->firstOrFail();
    [$this->maker, $this->checker] = exitOperators();
    $tenants = app(TenantContext::class);

    // Files: an employee document of the leaving tenant, and its warehouse snapshots.
    $tenants->runAs($this->tenant, function () {
        $employee = Employee::query()->orderBy('id')->firstOrFail();
        app(Documents::class)->store($employee, UploadedFile::fake()->create('offer.pdf', 12, 'application/pdf'), DocumentType::query()->first(), 'Offer letter');
    });
    Storage::disk('local')->put('warehouse/demo/2026-10-01/employees.jsonl', '{"id":1}');

    // Markedge's records of the customer: a billing profile and a discarded invoice (nothing open).
    $billing = indiaBilling();
    billingProfile($this->tenant, $billing['market'], $billing['operator']);
    $invoice = draftInvoice($this->tenant, $billing['market'], $billing['operator']);
    app(Invoices::class)->discard($invoice, 'Not needed (test)', $billing['operator']);

    // A second tenant with its own people and files.
    $this->other = provisionTenant('Staying Co');
    $tenants->runAs($this->other, function () {
        $employee = employeeWithUser();
        app(Documents::class)->store($employee, UploadedFile::fake()->create('stay.pdf', 8, 'application/pdf'), DocumentType::query()->first(), 'Stays');
    });
    Storage::disk('local')->put('warehouse/'.$this->other->slug.'/2026-10-01/employees.jsonl', '{"id":2}');
    $this->otherBefore = tenantRowCounts($this->other);
    $this->otherFiles = Storage::disk('local')->allFiles('tenants/'.$this->other->id);
});

it('deletes every eligible category of the leaving tenant, keeps Markedge\'s records and the exit evidence, and certifies it', function () {
    retentionFor(null, 0, $this->maker, $this->checker);
    $case = offboardAndClose($this->tenant, $this->maker);
    $export = deliveredExport($this->tenant, $this->maker, $case);
    $before = tenantRowCounts($this->tenant);
    expect($before['employees'])->toBeGreaterThan(0)->and($before['audit_events'])->toBeGreaterThan(0)->and($before['invoices'])->toBe(1);

    $deletion = approvedDeletion($case, $this->maker, $this->checker);
    expect($deletion->status)->toBe(DeletionStatus::Approved)
        ->and($deletion->scheduled_for->isFuture())->toBeTrue();

    // Nothing happens during the cooling-off.
    expect(app(TenantDeletions::class)->dispatchDue())->toMatchArray(['dispatched' => 0]);
    $this->travel(15)->days();
    expect(app(TenantDeletions::class)->dispatchDue())->toMatchArray(['dispatched' => 1]);

    $deletion = app(TenantDeletions::class)->find($deletion->id);
    expect($deletion->status)->toBe(DeletionStatus::Completed, (string) $deletion->failure);
    $after = tenantRowCounts($this->tenant);
    foreach ($after as $table => $count) {
        $category = DataInventory::categoryOf($table);
        if (in_array($category, ['billing', 'invoices', 'payments', 'subscriptions'], true)) {
            expect($count)->toBe($before[$table], "{$table} (Markedge's records: kept unchanged)");
        } elseif ($category === 'offboarding_records') {
            expect($count)->toBeGreaterThanOrEqual($before[$table], "{$table} (the exit's evidence: kept)");
        } else {
            expect($count)->toBe(0, "{$table} ({$category}) should be deleted");
        }
    }
    // Files: the tenant's documents and snapshots are gone; the export package is outside every tenant prefix and stays.
    expect(Storage::disk('local')->allFiles('tenants/'.$this->tenant->id))->toBe([])
        ->and(Storage::disk('local')->allFiles('warehouse/demo'))->toBe([])
        ->and(Storage::disk('local')->exists($export->path))->toBeTrue();
    // The tenant is a tombstone, the case is complete, and the certificate is signed and says what happened.
    expect($this->tenant->fresh()->status)->toBe(TenantStatus::Deleted)
        ->and($case->fresh()->status->value)->toBe('completed');
    $certificate = $deletion->certificate;
    expect(Signatures::verify('deletion-certificate', $certificate, $deletion->certificate_signature))->toBeTrue()
        ->and($certificate['final'])->toBeTrue()
        ->and(array_column($certificate['categories']['deleted'], 'category'))->toContain('people', 'employment', 'payroll', 'audit', 'warehouse_exports')
        ->and(array_column($certificate['categories']['retained'], 'category'))->toContain('billing', 'invoices', 'offboarding_records')
        ->and($certificate['audit_chain']['final_hash'])->toHaveLength(64)
        ->and($certificate['verification']['dangling'])->toBe([])
        ->and($certificate['export']['reference'])->toBe($export->reference)
        ->and(collect($certificate['external_dependencies'])->pluck('target'))->toContain('backups');
    // Audit: the tenant chain is gone as a whole; the platform chain records the exit and still verifies.
    expect(AuditEvent::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and(app(AuditIntegrityVerifier::class)->verify(null)['valid'])->toBeTrue()
        ->and(AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', 'DELETION_CERTIFIED')->where('metadata->subject_tenant_id', $this->tenant->id)->exists())->toBeTrue();
    // The other tenant: every row and every file is still there, and its chain still verifies.
    expect(tenantRowCounts($this->other))->toBe($this->otherBefore)
        ->and(Storage::disk('local')->allFiles('tenants/'.$this->other->id))->toBe($this->otherFiles)
        ->and(Storage::disk('local')->allFiles('warehouse/'.$this->other->slug))->not->toBe([])
        ->and(app(AuditIntegrityVerifier::class)->verify($this->other->id)['valid'])->toBeTrue();
});

it('anonymises sign-in accounts when the audit trail is retained, and leaves the tenant closed with the chain intact', function () {
    retentionFor(array_values(array_diff(array_keys(array_filter(DataInventory::CATEGORIES, fn ($c) => $c['action'] !== DataInventory::RETAIN)), ['audit'])), 0, $this->maker, $this->checker);
    retentionFor(['audit'], 120, $this->maker, $this->checker);
    $case = offboardAndClose($this->tenant, $this->maker);
    deliveredExport($this->tenant, $this->maker, $case);
    $plan = app(DeletionPlanner::class)->plan($this->tenant, $case);
    expect($plan['categories']['audit']['reason'])->toBe('RETENTION_RUNNING')
        ->and($plan['categories']['identity']['action'])->toBe(DataInventory::ANONYMISE)
        ->and($plan['categories']['identity']['anonymised_tables'])->toBe(['users']);
    $users = DB::table('users')->where('tenant_id', $this->tenant->id)->count();

    $deletion = approvedDeletion($case, $this->maker, $this->checker);
    $this->travel(15)->days();
    app(TenantDeletions::class)->dispatchDue();

    $deletion = app(TenantDeletions::class)->find($deletion->id);
    expect($deletion->status)->toBe(DeletionStatus::Completed, (string) $deletion->failure)
        ->and(DB::table('users')->where('tenant_id', $this->tenant->id)->count())->toBe($users)
        ->and(DB::table('users')->where('tenant_id', $this->tenant->id)->where('email', 'not like', 'deleted-user-%@deleted.invalid')->count())->toBe(0)
        ->and(DB::table('users')->where('tenant_id', $this->tenant->id)->where('name', '!=', 'Deleted user')->count())->toBe(0)
        ->and(DB::table('employees')->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and($this->tenant->fresh()->status)->toBe(TenantStatus::Closed)
        ->and($deletion->certificate['final'])->toBeFalse()
        ->and(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue()
        ->and(AuditEvent::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->count())->toBeGreaterThan(0);
});

it('protects everything payroll still needs while payroll is retained', function () {
    retentionFor(array_values(array_diff(array_keys(array_filter(DataInventory::CATEGORIES, fn ($c) => $c['action'] !== DataInventory::RETAIN)), ['payroll'])), 0, $this->maker, $this->checker);
    retentionFor(['payroll'], 96, $this->maker, $this->checker);
    $case = offboardAndClose($this->tenant, $this->maker);
    deliveredExport($this->tenant, $this->maker, $case);

    $plan = app(DeletionPlanner::class)->plan($this->tenant, $case);
    expect($plan['categories']['payroll']['reason'])->toBe('RETENTION_RUNNING')
        ->and(array_sum($plan['categories']['payroll']['tables']))->toBeGreaterThan(0);
    foreach (['employment', 'organisation'] as $protected) {
        expect($plan['categories'][$protected]['reason'])->toBe('PROTECTED_BY_DEPENDENCY', $protected)
            ->and(implode(' ', $plan['categories'][$protected]['protected_by']))->toContain('payroll');
    }
    // People are protected through employees (employees reference people), so payroll keeps the identity it needs.
    expect($plan['categories']['people']['action'])->toBe(DataInventory::RETAIN);
    $payroll = DB::table('payroll_entries')->where('tenant_id', $this->tenant->id)->count();
    $employees = DB::table('employees')->where('tenant_id', $this->tenant->id)->count();

    $deletion = approvedDeletion($case, $this->maker, $this->checker);
    $this->travel(15)->days();
    app(TenantDeletions::class)->dispatchDue();

    expect(app(TenantDeletions::class)->find($deletion->id)->status)->toBe(DeletionStatus::Completed)
        ->and(DB::table('payroll_entries')->where('tenant_id', $this->tenant->id)->count())->toBe($payroll)
        ->and(DB::table('employees')->where('tenant_id', $this->tenant->id)->count())->toBe($employees)
        ->and(DB::table('leave_requests')->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

it('records every step with its count, and nothing deleted twice when a finished deletion is run again', function () {
    retentionFor(null, 0, $this->maker, $this->checker);
    $case = offboardAndClose($this->tenant, $this->maker);
    deliveredExport($this->tenant, $this->maker, $case);
    $deletion = approvedDeletion($case, $this->maker, $this->checker);
    $this->travel(15)->days();
    app(TenantDeletions::class)->dispatchDue();

    $steps = TenantDeletionStep::query()->withoutTenancy()->where('tenant_deletion_id', $deletion->id)->get();
    expect($steps->where('kind', '!=', 'external')->pluck('status')->unique()->values()->all())->toBe(['done'])
        ->and($steps->firstWhere('target', 'employees')->processed_count)->toBe($steps->firstWhere('target', 'employees')->planned_count);
    // A duplicate job (or a second dispatch) does nothing: the deletion is no longer approved.
    expect(app(DeletionExecutor::class)->execute($deletion->id))->toBeFalse();
});
