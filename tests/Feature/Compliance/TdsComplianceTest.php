<?php

use App\Domain\Compliance\Jobs\GenerateTdsReturn;
use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Models\TdsAnnualLedger;
use App\Domain\Compliance\Models\TdsCertificate;
use App\Domain\Compliance\Models\TdsProfile;
use App\Domain\Compliance\Models\TdsQuarterlyReturn;
use App\Domain\Compliance\Models\TdsQuarterlyReturnEntry;
use App\Domain\Compliance\Services\Returns\StatutoryReturns;
use App\Domain\Compliance\Services\Tds\TdsCertificates;
use App\Domain\Compliance\Services\Tds\TdsLedgers;
use App\Domain\Compliance\Services\Tds\TdsQuarterlyReturns;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryStructure;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Payroll\Services\Salaries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ComplianceTestHelpers.php';

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-05 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    ['company' => $this->company, 'entity' => $this->entity, 'establishment' => $this->establishment] = complianceCompany();
    $this->tan = StatutoryRegistration::query()->where('registration_type', 'tan')->sole();
    TdsProfile::query()->create(['legal_entity_id' => $this->entity->id, 'tan_registration_id' => $this->tan->id, 'responsible_person_name' => 'R. Mehta', 'responsible_person_designation' => 'Director', 'responsible_person_pan' => 'AAAPM1234C']);
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->payrollApprover = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    ['generator' => $this->generator, 'approver' => $this->approver, 'filer' => $this->filer] = complianceUsers($this->tenant);
    $this->tdsManager = tenantUser($this->tenant, ['compliance.returns.view', 'compliance.tds.manage']);
    $this->tdsIssuer = tenantUser($this->tenant, ['compliance.returns.view', 'compliance.tds.manage']);
    $this->ledgers = app(TdsLedgers::class);
    $this->tds = app(TdsQuarterlyReturns::class);
    $this->returns = app(StatutoryReturns::class);

    $this->withPan = statutoryEmployee(2400000, $this->establishment, '100200300420', ['pan' => 'ABCDE1234F']);
    $this->noPan = statutoryEmployee(2400000, $this->establishment, '100200300421');
    foreach ([7, 8, 9] as $month) {
        finalizedPayroll($this->company, 2026, $month, $this->preparer, $this->payrollApprover);
    }
    $this->challans = fn (float $amount) => [['bsr_code' => '0510001', 'deposit_date' => '2026-10-07', 'challan_serial' => '00123', 'amount' => $amount]];
});

it('keeps an immutable annual ledger of what finalized payroll deducted, superseding rows when payroll changes', function () {
    $counts = $this->ledgers->sync($this->entity, '2026-27');
    $rows = TdsAnnualLedger::query()->where('status', 'active')->get();

    expect($counts['added'])->toBe(6)
        ->and($rows->pluck('quarter')->unique()->all())->toBe([2])
        ->and($rows->every(fn ($r) => (float) $r->tds_deducted > 0))->toBeTrue()
        ->and($this->ledgers->sync($this->entity, '2026-27'))->toBe(['added' => 0, 'superseded' => 0, 'withdrawn' => 0]);
    expect(fn () => $rows->first()->update(['tds_deducted' => 1]))->toThrow(RuntimeException::class, 'immutable');

    // September payroll is reopened and re-finalized with a raise: that month's rows are superseded.
    $september = PayrollRun::query()->whereHas('period', fn ($q) => $q->where('month', 9))->sole();
    app(PayrollRuns::class)->reopen($september, 'Raise', $this->payrollApprover);
    expect($this->ledgers->sync($this->entity, '2026-27')['withdrawn'])->toBe(2);
    app(Salaries::class)->assign($this->withPan, SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail(), 3000000, '2026-09-01', ['CONV' => 1600], 'revision', 'Raise');
    finalizedPayroll($this->company, 2026, 9, $this->preparer, $this->payrollApprover);
    $this->ledgers->sync($this->entity, '2026-27');

    expect(TdsAnnualLedger::query()->where('status', 'active')->count())->toBe(6)
        ->and(TdsAnnualLedger::query()->where('status', 'withdrawn')->count())->toBe(2);
});

it('builds Form No. 138 (earlier 24Q) for a quarter and blocks without deposit details, a TAN or valid PANs', function () {
    $return = $this->returns->validate($this->tds->generate($this->entity, '2026-27', 2, $this->generator), $this->generator);
    $run = TdsQuarterlyReturn::query()->where('statutory_return_id', $return->id)->sole();

    expect($return->form_code)->toBe('FORM_138')
        ->and($return->legacy_form_code)->toBe('FORM_24Q')
        ->and($return->establishment_id)->toBeNull()
        ->and($return->period_key)->toBe('2026-27-Q2')
        ->and($run->deductee_count)->toBe(2)
        ->and(TdsQuarterlyReturnEntry::query()->count())->toBe(6)
        ->and(TdsQuarterlyReturnEntry::query()->where('employee_id', $this->noPan->id)->pluck('reason_code')->unique()->all())->toBe(['NO_PAN'])
        ->and(TdsQuarterlyReturnEntry::query()->where('employee_id', $this->withPan->id)->first()->section_code)->toBe('392')
        ->and(DB::table('tds_quarterly_return_entries')->whereNotNull('pan')->value('pan'))->not->toContain('ABCDE1234F')
        ->and(collect($return->validation)->where('severity', 'blocking')->pluck('code'))->toContain('deposit_details_missing')
        ->and(collect($return->validation)->pluck('code'))->toContain('official_schema_unverified', 'pan_missing')
        ->and($return->reconciliation_status)->toBe('balanced');

    EmployeeStatutoryDetail::query()->where('employee_id', $this->withPan->id)->first()->update(['pan' => 'BAD']);
    $return = $this->returns->validate($this->tds->generate($this->entity, '2026-27', 2, $this->generator, ($this->challans)(1)), $this->generator);
    expect(collect($return->validation)->where('severity', 'blocking')->pluck('code')->all())->toContain('invalid_pan', 'deposit_short');

    EmployeeStatutoryDetail::query()->where('employee_id', $this->withPan->id)->first()->update(['pan' => 'ABCDE1234F']);
    $return = $this->returns->validate($this->tds->generate($this->entity, '2026-27', 2, $this->generator, ($this->challans)((float) TdsAnnualLedger::query()->where('status', 'active')->sum('tds_deducted'))), $this->generator);
    expect($return->status)->toBe('validated');

    $export = $this->returns->exportContent($this->returns->export($this->returns->approve($return, $this->approver), $this->generator), $this->filer);
    expect($export)->toContain('"PANNOTAVBL"')->and($return->refresh()->export_filename)->toStartWith('WORKING-SCHEDULE_FORM138_');
});

it('prepares Form No. 130 (earlier Form 16) only from a verified ledger and issues it with separation of duties', function () {
    $this->travelTo('2027-04-20 09:00:00');
    $total = (float) TdsAnnualLedger::query()->where('status', 'active')->sum('tds_deducted');
    $file = function (int $quarter, array $challans) {
        $r = $this->returns->validate($this->tds->generate($this->entity, '2026-27', $quarter, $this->generator, $challans), $this->generator);
        expect($r->status)->toBe('validated');
        $r = $this->returns->export($this->returns->approve($r, $this->approver), $this->generator);
        $r = $this->returns->recordSubmission($r, $this->filer, "TOKEN-Q{$quarter}", now()->subDay());

        return $this->returns->recordAcknowledgement($r, $this->filer, "PRN-Q{$quarter}", now());
    };

    $this->ledgers->sync($this->entity, '2026-27');
    $total = (float) TdsAnnualLedger::query()->where('status', 'active')->sum('tds_deducted');
    $file(2, ($this->challans)($total));
    expect(fn () => $this->ledgers->verify($this->entity, '2026-27', $this->tdsManager))->toThrow(RuntimeException::class, 'Q1 statement is not acknowledged');
    expect(fn () => app(TdsCertificates::class)->generate($this->entity, '2026-27', $this->tdsManager))->toThrow(RuntimeException::class, 'verified annual ledger');

    $file(1, []);
    $file(3, []);
    $q4 = $file(4, []);
    expect(TdsQuarterlyReturn::query()->where('statutory_return_id', $q4->id)->sole()->annexure_ii)->toHaveCount(2);

    $year = $this->ledgers->verify($this->entity, '2026-27', $this->tdsManager);
    expect($year->ledger_verified_at)->not->toBeNull();

    $certificates = app(TdsCertificates::class)->generate($this->entity, '2026-27', $this->tdsManager);
    $mine = $certificates->firstWhere('employee_id', $this->withPan->id);
    expect($certificates)->toHaveCount(2)
        ->and($mine->code)->toBe('FORM_130')
        ->and($mine->legacy_code)->toBe('FORM_16')
        ->and($mine->snapshot['part_b']['tds_deducted'])->toEqual(round((float) TdsAnnualLedger::query()->where('employee_id', $this->withPan->id)->where('status', 'active')->sum('tds_deducted'), 2))
        ->and($mine->snapshot['part_a']['employee']['pan_last4'])->toBe('234F')
        ->and($mine->snapshot['part_a']['quarterly_statements'])->toHaveKey('2026-27-Q2')
        ->and(json_encode($mine->snapshot))->not->toContain('ABCDE1234F');

    expect(fn () => $mine->fresh()->update(['snapshot' => []]))->toThrow(RuntimeException::class, 'immutable');
    expect(fn () => app(TdsCertificates::class)->issue($mine, $this->tdsManager))->toThrow(RuntimeException::class, 'Separation of duties');
    expect(app(TdsCertificates::class)->issue($mine, $this->tdsIssuer, 'TRACES-ABC123')->status)->toBe('issued');

    // Regenerating an unchanged ledger creates nothing new.
    expect(app(TdsCertificates::class)->generate($this->entity, '2026-27', $this->tdsManager))->toHaveCount(0)
        ->and(TdsCertificate::query()->count())->toBe(2);
});

it('generates statements through a unique tenant-aware job', function () {
    $job = new GenerateTdsReturn($this->entity->id, '2026-27', 2, $this->generator->id);
    expect($job->uniqueId())->toBe("tds-return-{$this->tenant->id}-{$this->entity->id}-2026-27-Q2");
    dispatch($job);
    expect(StatutoryReturn::query()->where('return_type', 'TDS')->sole()->status)->toBe('calculated');
});
