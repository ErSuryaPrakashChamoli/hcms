<?php

use App\Domain\Compensation\Imports\UploadTimeline;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Payroll\Contracts\SalaryArrearsWriter;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\PayrollMonthReopen;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Payroll\Services\SalaryArrears;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
 | Owner, 2026-11-27: a salary assigned or revised from a month whose payroll is already finalised keeps its true date,
 | and each closed month's difference is paid (or recovered) as arrears in the next open month, pay item by pay item.
 | STANDARD at a CTC of 6,00,000 pays Basic 20,000 a month; at 7,50,000, 25,000.
 */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->payroll = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->checker = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->payroll);
    $this->company = payrollCompany();
    $this->runs = app(PayrollRuns::class);
    $this->employee = salariedEmployee(600000);
    EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'X', 'bank_name' => 'B', 'account_number' => '1234567890', 'ifsc' => 'BANK0000001', 'account_type' => 'savings', 'is_primary' => true]);
    // September is calculated, approved and finalised on the 6,00,000 salary.
    $this->september = $this->runs->finalize($this->runs->approve($this->runs->validate($this->runs->calculate($this->runs->open($this->company, 2026, 9))), $this->checker), $this->checker);
    $this->entry = PayrollEntry::query()->where('payroll_run_id', $this->september->id)->sole();
    $this->travelTo('2026-10-05 09:00:00');
    $this->arrears = fn () => PayrollAdjustment::query()->where('employee_id', $this->employee->id)->where('source_type', SalaryArrears::SOURCE)->orderBy('id')->get();
});

it('pays a revision dated in a finalised month as arrears of each pay item in the next open month, leaving the closed month untouched', function () {
    $net = (float) $this->entry->net_pay;
    $row = compensate($this->employee, 750000, '2026-09-01', ['CONV' => 1600], 'revision', 'Back-dated increment');

    $october = PayrollPeriod::for($this->company, 2026, 10);
    $basic = ($this->arrears)()->firstWhere('name', 'Basic arrear Sep 2026');
    expect($row->effective_from->toDateString())->toBe('2026-09-01')
        ->and((float) $this->entry->fresh()->net_pay)->toBe($net)
        ->and($basic)->not->toBeNull()
        ->and((float) $basic->amount)->toBe(5000.0)
        ->and($basic->type)->toBe('arrear')
        ->and($basic->status)->toBe('approved')
        ->and((int) $basic->payroll_period_id)->toBe((int) $october->id)
        ->and((int) $basic->reference_period_id)->toBe((int) $this->september->payroll_period_id)
        ->and($basic->note)->toContain('paid 20,000.00, due 25,000.00');

    // October pays the new salary and the September arrears; the payslip line names the month.
    $c = app(PayrollCalculator::class)->calculate($this->employee->fresh(), $october);
    expect(collect($c->lines)->firstWhere('name', 'Basic arrear Sep 2026')['amount'])->toBe(5000.0)
        ->and($c->amount('BASIC'))->toBe(25000.0)
        // The arrear follows its pay item: Basic is PF wages, so October's PF wages hold the new Basic and the arrear.
        ->and($c->pfWages())->toBeGreaterThanOrEqual(30000.0);
});

it('pays only the days after a mid-month date, and only what is still owed when the month was already part-paid by arrears', function () {
    compensate($this->employee, 750000, '2026-09-16', ['CONV' => 1600], 'revision', 'From the 16th');
    expect((float) ($this->arrears)()->firstWhere('name', 'Basic arrear Sep 2026')->amount)->toBe(2500.0); // 5,000 × 15/30

    // A correction of the same change: 7,80,000 (Basic 26,000) from the 16th is due 6,000 × 15/30 = 3,000 in all; 2,500
    // is already recorded, so 500 more — not another 3,000.
    compensate($this->employee, 780000, '2026-09-16', ['CONV' => 1600], 'correction', 'Corrected amount');
    $basic = ($this->arrears)()->where('name', 'Basic arrear Sep 2026')->pluck('amount')->map(fn ($a) => (float) $a)->all();
    expect($basic)->toBe([2500.0, 500.0]);
});

it('recovers a back-dated decrease as a deduction that names no pay item, so PF and ESI wages are not cut', function () {
    compensate($this->employee, 540000, '2026-09-01', ['CONV' => 1600], 'revision', 'Corrected downwards');
    $recovery = ($this->arrears)()->firstWhere('name', 'Basic recovery Sep 2026');
    expect($recovery->type)->toBe('recovery')
        ->and($recovery->salary_component_id)->toBeNull()
        ->and((float) $recovery->amount)->toBe(2000.0) // Basic 18,000 instead of 20,000
        ->and($recovery->note)->toEndWith('pay item BASIC');

    $c = app(PayrollCalculator::class)->calculate($this->employee->fresh(), PayrollPeriod::for($this->company, 2026, 10));
    expect(collect($c->lines)->firstWhere('name', 'Basic recovery Sep 2026')['type'])->toBe('deduction')
        ->and($c->pfWages())->toBeGreaterThanOrEqual($c->amount('BASIC'));
});

it('previews the closed months and the paying month for upload checks, and refuses the date as before when arrears are off', function () {
    $reach = app(SalaryArrearsWriter::class)->reach($this->employee->id, Carbon::parse('2026-09-10'));
    expect($reach['months'][0]['label'])->toBe(PayrollPeriod::for($this->company, 2026, 9)->label())
        ->and($reach['payout'])->toBe('Oct 2026')
        ->and(app(SalaryArrearsWriter::class)->reach($this->employee->id, Carbon::parse('2026-10-01')))->toBeNull();

    $content = ['effective_from' => '2026-09-10', 'salary_structure_id' => SalaryStructure::query()->where('code', 'STANDARD')->value('id'), 'ctc_annual' => 750000.0,
        'currency' => 'INR', 'component_values' => ['CONV' => 1600.0], 'variable_target_annual' => null, 'change_type' => 'revision'];
    [$action, $note] = app(UploadTimeline::class)->against($this->employee, $content, $this->company->id);
    expect($action)->toBe('create')->and($note)->toContain('paid in Oct 2026 payroll');

    app(SettingsRepository::class)->set('payroll.arrears.back_dated', false);
    [$action, $note] = app(UploadTimeline::class)->against($this->employee, $content, $this->company->id);
    expect($action)->toBe('error')->and($note)->toContain('finalized through')
        ->and(fn () => compensate($this->employee, 750000, '2026-09-01', ['CONV' => 1600], 'revision', 'Back-dated'))->toThrow(RuntimeException::class, 'closed payroll period')
        ->and(($this->arrears)())->toHaveCount(0);
});

it('will not reopen a month whose difference waits as arrears, so the new salary is never paid twice', function () {
    compensate($this->employee, 750000, '2026-09-01', ['CONV' => 1600], 'revision', 'Back-dated increment');
    $blocker = app(PayrollMonthReopen::class)->blocker(PayrollRun::query()->findOrFail($this->september->id));
    expect($blocker)->toContain('Salary arrears for')->toContain('delete those arrears');

    ($this->arrears)()->each->delete();
    expect(app(PayrollMonthReopen::class)->blocker(PayrollRun::query()->findOrFail($this->september->id)))->toBeNull();
});

it('lists the arrears a change recorded for its own page', function () {
    compensate($this->employee, 750000, '2026-09-01', ['CONV' => 1600], 'revision', 'Back-dated increment');
    $change = CompensationChange::query()->where('employee_id', $this->employee->id)->where('change_type', 'revision')->sole();
    $rows = SalaryArrears::recordedFor($change->id);
    expect(collect($rows)->firstWhere('name', 'Basic arrear Sep 2026'))->toMatchArray(['amount' => 5000.0, 'payout' => 'Oct 2026', 'status' => 'approved']);
});
