<?php

use App\Domain\Compliance\Jobs\GenerateEsiReturn;
use App\Domain\Compliance\Models\EsiReturnEntry;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Models\StatutorySnapshot;
use App\Domain\Compliance\Services\Returns\EsiReturns;
use App\Domain\Compliance\Services\Returns\StatutoryReturns;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
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
    ['company' => $this->company, 'establishment' => $this->establishment] = complianceCompany();
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->payrollApprover = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    ['generator' => $this->generator, 'approver' => $this->approver, 'filer' => $this->filer] = complianceUsers($this->tenant);
    $this->esi = app(EsiReturns::class);
    $this->returns = app(StatutoryReturns::class);

    $this->low = statutoryEmployee(240000, $this->establishment, '100200300401', ['esic_number' => '3100123456']);   // gross 19,040: ESI applies
    $this->high = statutoryEmployee(600000, $this->establishment, '100200300400', ['esic_number' => '3100123457']);  // above the ceiling
});

it('builds the ESI return from finalized ESI lines inside the rule\'s contribution period and reconciles it', function () {
    finalizedPayroll($this->company, 2026, 9, $this->preparer, $this->payrollApprover);
    $return = $this->returns->validate($this->esi->generate($this->establishment, 2026, 9, $this->generator), $this->generator);
    $entry = EsiReturnEntry::query()->sole();

    expect($entry->employee_id)->toBe($this->low->id)
        ->and($entry->contribution_period)->toBe('2026-04..2026-09')
        ->and((float) $entry->calc_wages)->toBe(19040.0)
        ->and((float) $entry->calc_ee_contribution)->toBe(ceil(19040 * 0.0075))
        ->and($entry->maskedIp())->toBe('••••••3456')
        ->and(DB::table('esi_return_entries')->value('ip_number'))->not->toContain('3100123456')
        ->and($return->reconciliation_status)->toBe('balanced')
        ->and($return->status)->toBe('validated')
        ->and(collect($return->validation)->pluck('code'))->toContain('rule_unverified', 'low_wage_exemption_not_checked', 'export_format_unverified');

    $return = $this->returns->approve($return, $this->approver);
    expect(StatutorySnapshot::query()->where('statutory_return_id', $return->id)->count())->toBe(1);
    $return = $this->returns->export($return, $this->generator);
    $csv = $this->returns->exportContent($return, $this->filer);
    expect($return->export_filename)->toStartWith('UNVERIFIED-FORMAT_ESI_MC_')
        ->and($csv)->toContain('"IP Number"')->toContain('"3100123456"')->toContain('"19040.00"');
});

it('blocks on missing, malformed and duplicate IP numbers', function () {
    $twin = statutoryEmployee(240000, $this->establishment, '100200300409', ['esic_number' => '3100123456']);
    $noIp = statutoryEmployee(230000, $this->establishment, '100200300408');
    finalizedPayroll($this->company, 2026, 9, $this->preparer, $this->payrollApprover);

    $return = $this->returns->validate($this->esi->generate($this->establishment, 2026, 9, $this->generator), $this->generator);
    $codes = collect($return->validation)->where('severity', 'blocking')->pluck('code');
    expect($codes)->toContain('duplicate_ip', 'missing_ip')->and($return->status)->toBe('calculated');

    EmployeeStatutoryDetail::query()->where('employee_id', $noIp->id)->first()->update(['esic_number' => '12345']);
    EmployeeStatutoryDetail::query()->where('employee_id', $twin->id)->first()->update(['esic_number' => '3100999999']);
    $return = $this->returns->validate($this->esi->generate($this->establishment, 2026, 9, $this->generator), $this->generator);
    expect(collect($return->validation)->where('severity', 'blocking')->pluck('code')->all())->toBe(['invalid_ip']);
});

it('warns when an employee covered earlier in the contribution period drops out of ESI', function () {
    finalizedPayroll($this->company, 2026, 8, $this->preparer, $this->payrollApprover);
    $this->returns->approve($this->returns->validate($this->esi->generate($this->establishment, 2026, 8, $this->generator), $this->generator), $this->approver);

    compensate($this->low, 360000, '2026-09-01', ['CONV' => 1600], 'revision', 'Increment');
    finalizedPayroll($this->company, 2026, 9, $this->preparer, $this->payrollApprover);
    $september = $this->returns->validate($this->esi->generate($this->establishment, 2026, 9, $this->generator), $this->generator);

    expect(collect($september->validation)->firstWhere('code', 'contribution_period_continuity'))->not->toBeNull()
        ->and(collect($september->validation)->firstWhere('code', 'contribution_period_continuity')['employee_id'])->toBe($this->low->id);
});

it('generates through a unique tenant-aware job and refuses unfinalized payroll', function () {
    expect(fn () => $this->esi->generate($this->establishment, 2026, 9, $this->generator))->toThrow(RuntimeException::class, 'No finalized payroll');

    finalizedPayroll($this->company, 2026, 9, $this->preparer, $this->payrollApprover);
    $job = new GenerateEsiReturn($this->establishment->id, 2026, 9, $this->generator->id);
    expect($job->uniqueId())->toBe("esi-return-{$this->tenant->id}-{$this->establishment->id}-2026-09");
    dispatch($job);
    expect(StatutoryReturn::query()->where('return_type', 'ESI')->sole()->status)->toBe('calculated');
});
