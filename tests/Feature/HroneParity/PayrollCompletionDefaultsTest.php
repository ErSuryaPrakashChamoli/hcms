<?php

use App\Domain\Compensation\Models\SalaryStructureVersion;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\BankFile;
use App\Domain\Payroll\Services\PaymentModes;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Platform\Services\SettingsRepository;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
 | HROne setup parity BP-2 / BP-9 / BP-13: every new setting, column and record left at its default reproduces today's
 | payroll — the same lines, net pay and exceptions, the same engine version, the same bank file byte for byte and the
 | same structure version checksum.
 */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
});

/** The calculation of every employee for September, as comparable data. */
function pcdSnapshot(array $employees, PayrollPeriod $period): array
{
    return collect($employees)->map(function ($employee) use ($period) {
        $c = app(PayrollCalculator::class)->calculate($employee->refresh(), $period);

        return ['lines' => collect($c->lines)->map(fn (array $l) => [$l['code'], $l['type'], $l['amount'], $l['taxable'], $l['classification']])->all(),
            'net' => $c->net(), 'gross' => $c->gross(), 'paid_days' => $c->paidDays, 'exceptions' => $c->exceptions, 'version' => $c->inputs['calculation_version']];
    })->all();
}

/** The salary file exactly as BankFile wrote it before BP-13: every entry with net pay, in the primary account. */
function pcdLegacyCsv(PayrollRun $run): string
{
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['Employee code', 'Beneficiary name', 'Bank', 'IFSC', 'Account number', 'Amount', 'Narration']);
    foreach (PayrollEntry::query()->with(['employee.person', 'employee.bankAccounts'])->where('payroll_run_id', $run->id)->where('status', '!=', 'exception')->where('net_pay', '>', 0)->orderBy('id')->get() as $entry) {
        $bank = $entry->employee->bankAccounts->firstWhere('is_primary', true);
        fputcsv($out, [$entry->employee->employee_code, $bank?->account_holder_name ?: $entry->employee->person?->full_name, $bank?->bank_name, $bank?->ifsc, $bank?->account_number,
            number_format((float) $entry->net_pay, 2, '.', ''), 'Salary '.$run->period->label()]);
    }
    rewind($out);

    return stream_get_contents($out);
}

it('computes the same run, bank file and engine version before and after every default is written out', function () {
    $a = salariedEmployee(600000, from: '2026-01-01');
    $b = salariedEmployee(450000, from: '2026-01-01', componentValues: ['CONV' => 1200]);
    foreach ([$a, $b] as $i => $e) {
        EmployeeBankAccount::create(['employee_id' => $e->id, 'account_holder_name' => 'Holder '.$i, 'bank_name' => 'HDFC', 'account_number' => '12345678'.$i, 'ifsc' => 'HDFC000000'.$i, 'is_primary' => true]);
    }
    $period = PayrollPeriod::for($this->company, 2026, 9);
    $before = pcdSnapshot([$a, $b], $period);
    expect($before[0]['version'])->toBe('payroll-2.5')->and($before[0]['net'])->toBeGreaterThan(0.0);

    // Every new organisation setting at its default, written as a tenant setting.
    foreach (['payroll.claims.one_open_per_component' => false, 'payroll.claims.not_above_bills' => false, 'payroll.claims.reminder_task' => 'off', 'payroll.variable.task_day' => 0,
        'compensation.self_service.show_variable_plan' => false, 'payroll.pay_modes' => ['online_transfer'], 'payroll.bank.show_ifsc_branch' => true] as $key => $value) {
        expect(config('peopleos.settings')[$key])->toBe($value);
        app(SettingsRepository::class)->set($key, $value, 'Defaults written out');
    }
    // A structure version naming its CTC mode and balancing component (SPECIAL already balances to CTC, last).
    $special = SalaryComponent::query()->where('code', 'SPECIAL')->firstOrFail();
    $version = approveStructureVersion('STANDARD', '2026-02-01', [], ['ctc_mode' => 'ctc', 'balancing_component_id' => $special->id]);
    expect($version->formulaOptions())->toBe(['ctc_mode' => 'ctc', 'balancing_component_id' => $special->id])
        ->and($version->components()->where('salary_component_id', $special->id)->value('formula_override'))->toBeNull();
    // Both employees paid by bank transfer, explicitly.
    foreach ([$a, $b] as $e) {
        app(PaymentModes::class)->set($e, '2026-01-01', 'online_transfer', null, null, null, 'Bank transfer, as before', $this->hr);
    }

    expect(pcdSnapshot([$a, $b], $period))->toBe($before);

    $runs = app(PayrollRuns::class);
    $run = $runs->calculate($runs->open($this->company, 2026, 9, $this->hr), $this->hr);
    $run = $runs->finalize($runs->approve($runs->validate($run), $this->approver, 'ok'), $this->approver);
    expect(app(BankFile::class)->csv($run))->toBe(pcdLegacyCsv($run))
        ->and(app(BankFile::class)->groups($run))->toBe([])
        ->and(app(BankFile::class)->fileName($run))->toBe('salary-2026-09.csv')
        ->and(PayrollEntry::query()->where('payroll_run_id', $run->id)->pluck('inputs')->every(fn ($inputs) => ($inputs['calculation_version'] ?? null) === 'payroll-2.5'))->toBeTrue();
});

it('keeps the checksum of a structure version approved without the new options', function () {
    $version = approveStructureVersion('STANDARD', '2026-03-01');
    $legacy = hash('sha256', json_encode([
        $version->only(['salary_structure_id', 'version', 'currency', 'pay_frequency', 'company_id', 'grade_ids', 'applicability']), $version->effective_from->toDateString(),
        $version->components()->get()->map(fn ($c) => $c->only(['salary_component_id', 'formula_override', 'pay_nature', 'frequency', 'sort_order']))->all(),
        ...($version->parameters ? [$version->parameters] : []),
    ]));

    expect(SalaryStructureVersion::query()->find($version->id)->checksum)->toBe($legacy)
        ->and(SalaryStructureVersion::query()->find($version->id)->ctc_mode)->toBe('ctc');
});
