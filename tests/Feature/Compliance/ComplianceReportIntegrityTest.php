<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\ComplianceReports\Services\ComplianceReports;
use App\Domain\ComplianceReports\Support\ReportPeriod;
use App\Domain\Payroll\Services\PayrollRuns;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ComplianceTestHelpers.php';

/*
 | A statutory report, once built, does not change: later payroll changes go into a new pack, a pack whose file
 | changed after it was built is refused, and figures come from finalized payroll only, never an open run.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-11-05 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    ['company' => $this->company, 'establishment' => $this->establishment] = complianceCompany();
    $this->employee = statutoryEmployee(240000, $this->establishment, '100200300610');
    [$this->preparer, $this->approver] = [tenantUser($this->tenant, ['payroll.*', 'employee.*']), tenantUser($this->tenant, ['payroll.*', 'employee.*'])];
    finalizedPayroll($this->company, 2026, 9, $this->preparer, $this->approver);
    $this->hr = tenantUser($this->tenant, ['compliance.reports.view', 'compliance.reports.generate']);
    $this->reports = app(ComplianceReports::class);
    $this->pf = fn (int $month) => firstSheet($this->reports->single($this->company, ReportPeriod::month(2026, $month), "pf_challan|establishment|{$this->establishment->id}", $this->hr)['content']);
    $this->actingAs($this->hr);
});

it('never rebuilds a built pack: a later payroll change goes into a new pack, and the first downloads as it was built', function () {
    $first = $this->reports->request($this->company, ReportPeriod::month(2026, 9), $this->hr);
    [$sha, $runs] = [$first->sha256, $first->metadata['payroll_runs']];
    expect($first->status)->toBe('completed')->and($runs)->not->toBeEmpty();

    // A finalized figure changed afterwards, outside the payroll lifecycle.
    DB::table('payroll_entry_lines')->where('code', 'BASIC')->update(['amount' => 9999]);

    $download = $this->reports->download($first->refresh(), $this->hr);
    expect($first->sha256)->toBe($sha)->and(hash('sha256', $download['content']))->toBe($sha);

    $second = $this->reports->request($this->company, ReportPeriod::month(2026, 9), $this->hr);
    expect($second->id)->not->toBe($first->id)
        ->and($second->metadata['payroll_runs'])->toBe($runs)
        ->and($first->refresh()->sha256)->toBe($sha);
});

it('refuses to hand over a pack whose file changed after it was built', function () {
    $pack = $this->reports->request($this->company, ReportPeriod::month(2026, 9), $this->hr);
    Storage::disk('local')->put($pack->path, 'replaced');
    $downloads = fn () => AuditEvent::query()->where('entity_type', $pack->getMorphClass())->where('entity_id', (string) $pack->id)->where('action', 'DOWNLOAD')->count();

    expect(fn () => $this->reports->download($pack, $this->hr))->toThrow(RuntimeException::class, 'no longer matches the fingerprint')
        ->and($downloads())->toBe(0);
});

it('reads finalized payroll only: an open run of the month never feeds a statutory report', function () {
    $runs = app(PayrollRuns::class);
    $runs->calculate($runs->open($this->company, 2026, 10, $this->preparer), $this->preparer); // calculated, not finalized
    $this->actingAs($this->hr);

    $october = ($this->pf)(10);
    expect(collect($october)->flatten()->contains(fn ($c) => str_contains((string) $c, 'Payroll for October 2026 is not finalized (1 open run(s))')))->toBeTrue()
        ->and(collect($october)->flatten()->contains('100200300610'))->toBeFalse();

    // September, finalized, has the employee.
    expect(collect(($this->pf)(9))->flatten()->contains(fn ($c) => str_contains((string) $c, '0610')))->toBeTrue();
});
