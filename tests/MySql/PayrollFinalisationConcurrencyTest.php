<?php

use App\Domain\Compliance\Models\EstablishmentStatutoryProfile;
use App\Domain\Compliance\Models\StatutoryProfileCorrection;
use App\Domain\Compliance\Services\StatutoryProfileCorrections;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use App\Domain\Organisation\Services\EstablishmentAssignments;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Services\PayrollRuns;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Feature/Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Feature/Payroll/PayrollTestHelpers.php';
require_once __DIR__.'/ConcurrencyHelpers.php';

/*
 | Finalized payroll under real concurrency on MySQL. A return to draft and a finalization of the same approved run
 | decide on the locked row, so the run ends in exactly one of the two states and a finalized run is never overwritten
 | to draft. Two approved corrections of the same statutory profile applied at once take the establishment lock, so
 | only one is applied and the other finds the profile changed. Same opt-in as the other suites
 | (PEOPLEOS_MYSQL_CONCURRENCY_DB, name containing "concurrency"); SQLite runs skip these and claim nothing about MySQL.
 */

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_CONCURRENCY_DB', '');
    if ($database === '' || ! str_contains($database, 'concurrency') || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_CONCURRENCY_DB to a disposable MySQL database (name containing "concurrency") and enable pcntl.');
    }
    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'concurrency', 'queue.default' => 'sync']);
    DB::purge('concurrency');
    if (! ($GLOBALS['peopleos_concurrency_migrated'] ?? false)) {
        Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
        $GLOBALS['peopleos_concurrency_migrated'] = true;
    }
    $this->travelTo('2026-09-21 09:00:00');
    if (! ($GLOBALS['peopleos_concurrency_rules'] ?? false)) {
        syncComplianceRules();
        $GLOBALS['peopleos_concurrency_rules'] = true;
    }
    $this->tenant = provisionTenant('Race '.uniqid());
    actAsTenant($this->tenant);
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->preparer);
    $this->company = payrollCompany();
});

it('ends a simultaneous finalization and return to draft of one approved run in exactly one of the two states', function () {
    salariedEmployee(600000);
    $runs = app(PayrollRuns::class);
    $run = $runs->approve($runs->validate($runs->calculate($runs->open($this->company, 2026, 9, $this->preparer), $this->preparer)), $this->approver, 'ok');

    $results = race([
        function () use ($run) {
            actAsTenant($this->tenant);
            $this->actingAs($this->approver);
            app(PayrollRuns::class)->finalize($run, $this->approver);
        },
        function () use ($run) {
            actAsTenant($this->tenant);
            $this->actingAs($this->approver);
            app(PayrollRuns::class)->reopen($run, 'Rule superseded', $this->approver);
        },
    ], 0.4, ['eloquent.updating: '.PayrollRun::class]);

    actAsTenant($this->tenant);
    $status = PayrollRun::query()->whereKey($run->id)->value('status');
    $period = PayrollPeriod::query()->whereKey($run->payroll_period_id)->value('status');
    expect(collect($results)->filter(fn (string $r) => $r === 'ok'))->toHaveCount(1)->and($status)->toBeIn(['finalized', 'draft']);
    if ($status === 'finalized') {
        expect($results[1])->toContain('A finalized run cannot be reopened')->and(Payslip::query()->count())->toBe(1)->and($period)->toBe('closed');
    } else {
        expect($results[0])->toContain('Only an approved run can be finalized')->and(Payslip::query()->count())->toBe(0)->and($period)->toBe('open');
    }
});

it('applies one of two approved corrections of the same profile when both are applied at the same moment', function () {
    $requester = tenantUser($this->tenant, ['compliance.registrations.view', 'compliance.registrations.manage']);
    $reviewer = tenantUser($this->tenant, ['compliance.registrations.view', 'compliance.profile_corrections.approve']);
    $entity = LegalEntity::query()->where('company_id', $this->company->id)->sole();
    $pune = Establishment::query()->create(['legal_entity_id' => $entity->id, 'code' => 'PUNE', 'name' => 'Pune', 'state' => 'MH', 'effective_from' => '2020-01-01']);
    $epf = EstablishmentStatutoryProfile::query()->create(['establishment_id' => $pune->id, 'statute' => 'EPF', 'applicable' => true, 'effective_from' => '2020-01-01']);
    EstablishmentStatutoryProfile::query()->create(['establishment_id' => $pune->id, 'statute' => 'ESI', 'applicable' => true, 'effective_from' => '2020-01-01']);
    $employee = salariedEmployee(240000);
    app(EstablishmentAssignments::class)->assign($employee, $pune, '2025-01-01', 'Pune');
    EmployeeStatutoryDetail::query()->create(['employee_id' => $employee->id, 'pf_applicable' => false]); // no EPF amount: configuration only
    $runs = app(PayrollRuns::class);
    $run = $runs->calculate($runs->open($this->company, 2026, 9, $this->preparer), $this->preparer);
    PayrollRun::query()->whereKey($run->id)->update(['status' => 'finalized']);

    $corrections = app(StatutoryProfileCorrections::class);
    $both = [
        $corrections->requestChange($epf, ['effective_to' => '2026-08-31'], $requester, 'EPF code surrendered on 31 Aug'),
        $corrections->requestChange($epf, ['effective_to' => '2026-08-31'], $requester, 'The same surrender, raised twice'),
    ];
    foreach ($both as $correction) {
        $corrections->approve($correction, $reviewer, 'Matches the EPFO letter');
    }

    $results = race(array_map(fn (StatutoryProfileCorrection $correction) => function () use ($correction, $requester) {
        actAsTenant($this->tenant);
        $this->actingAs($requester);
        app(StatutoryProfileCorrections::class)->apply($correction->fresh(), $requester);
    }, $both), 0.4, ['eloquent.updating: '.EstablishmentStatutoryProfile::class]);

    actAsTenant($this->tenant);
    expect(collect($results)->filter(fn (string $r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->reject(fn (string $r) => $r === 'ok')->first())->toContain('profile changed')
        ->and(StatutoryProfileCorrection::query()->whereIn('id', collect($both)->pluck('id'))->pluck('status')->sort()->values()->all())->toBe(['applied', 'approved'])
        ->and($epf->fresh()->effective_to->toDateString())->toBe('2026-08-31');
});
