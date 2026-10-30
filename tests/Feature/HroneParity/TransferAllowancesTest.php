<?php

use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\TransferRequest;
use App\Domain\Employment\Services\TransferRequests;
use App\Domain\Organisation\Models\Department;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Filament\Resources\TransferRequests\Pages\ListTransferRequests;
use Illuminate\Support\Collection;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* HROne setup parity 08.6 — Workforce → Transfer allowances: pay components marked as transfer allowances, granted on a transfer request, paid once through payroll. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->preparer);
    $this->company = payrollCompany();
    $this->runs = app(PayrollRuns::class);
    $this->employee = salariedEmployee(600000);
    EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    $this->relocation = SalaryComponent::create(['name' => 'Relocation allowance', 'code' => 'RELOC', 'type' => 'earning', 'classification' => 'allowance', 'calculation_method' => 'fixed', 'taxable' => true, 'pf_applicable' => false, 'esi_applicable' => false,
        'include_in_ctc' => false, 'include_in_gross' => true, 'is_recurring' => false, 'transfer_allowance' => true, 'status' => 'active', 'effective_from' => '2020-01-01']);
    $this->packing = SalaryComponent::create(['name' => 'Packing and moving', 'code' => 'PACKING', 'type' => 'reimbursement', 'classification' => 'reimbursement', 'calculation_method' => 'fixed', 'taxable' => false, 'pf_applicable' => false, 'esi_applicable' => false,
        'include_in_ctc' => false, 'include_in_gross' => false, 'is_recurring' => false, 'transfer_allowance' => true, 'status' => 'active', 'effective_from' => '2020-01-01']);
    $this->sales = Department::factory()->create(['name' => 'Sales']);
});

function tallowCalculate(int $month): PayrollRun
{
    return test()->runs->calculate(test()->runs->open(test()->company, 2026, $month, test()->preparer), test()->preparer);
}

/** @return Collection<int, PayrollEntryLine> the run's lines paid from transfer requests */
function tallowLines(PayrollRun $run)
{
    return PayrollEntryLine::query()->whereHas('entry', fn ($q) => $q->where('payroll_run_id', $run->id))->where('basis->source->key', 'transfer_allowance')->orderBy('sort_order')->get();
}

it('keeps payroll results unchanged when no transfer grants an allowance', function () {
    $baseline = tallowCalculate(9);
    $before = PayrollEntry::query()->where('payroll_run_id', $baseline->id)->sole();
    $codes = $before->lines()->pluck('code')->all();

    $request = app(TransferRequests::class)->request($this->employee, '2026-09-25', ['department_id' => $this->sales->id], null, null, 'Plain transfer', $this->preparer);
    app(TransferRequests::class)->approve($request, null, $this->approver);
    $run = tallowCalculate(9);
    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->sole();

    expect($request->refresh()->allowances)->toBeNull()->and($request->summary())->toBe('Department → Sales')
        ->and(tallowLines($run))->toHaveCount(0)
        ->and($entry->inputs['input_sources'] ?? null)->toBeNull()
        ->and($entry->lines()->pluck('code')->all())->toBe($codes)
        ->and((float) $entry->net_pay)->toBe((float) $before->net_pay)->and((float) $entry->gross)->toBe((float) $before->gross)
        ->and($run->calculation_version)->toBe(PayrollCalculator::VERSION)->and(PayrollCalculator::VERSION)->toBe('payroll-2.5');
});

it('grants only pay components marked as transfer allowances, with an amount, once each', function () {
    $basic = SalaryComponent::query()->where('code', 'BASIC')->firstOrFail();
    $transfers = app(TransferRequests::class);
    $request = fn (array $allowances) => $transfers->request($this->employee, '2026-09-25', ['department_id' => $this->sales->id], null, null, null, $this->preparer, $allowances);

    expect(fn () => $request([['salary_component_id' => $basic->id, 'amount' => 1000]]))->toThrow(RuntimeException::class, 'marked as a transfer allowance')
        ->and(fn () => $request([['salary_component_id' => $this->relocation->id, 'amount' => 0]]))->toThrow(RuntimeException::class, 'Give the amount of Relocation allowance')
        ->and(fn () => $request([['salary_component_id' => $this->relocation->id, 'amount' => 10], ['salary_component_id' => $this->relocation->id, 'amount' => 20]]))->toThrow(RuntimeException::class, 'listed twice');

    $this->relocation->update(['status' => 'inactive']);
    expect(fn () => $request([['salary_component_id' => $this->relocation->id, 'amount' => 10]]))->toThrow(RuntimeException::class, 'marked as a transfer allowance');
    $this->relocation->update(['status' => 'active']);

    Livewire::test(ListTransferRequests::class)->assertOk()
        ->callAction('request', ['employee_id' => $this->employee->id, 'effective_date' => '2026-09-25', 'changes' => ['department_id' => $this->sales->id], 'allowances' => [['salary_component_id' => $this->relocation->id, 'amount' => '25000']]])
        ->assertHasNoActionErrors();
    expect($this->employee->refresh()->id)->toBeInt()
        ->and(TransferRequest::query()->sole()->allowances)->toEqual([['salary_component_id' => $this->relocation->id, 'amount' => 25000]])
        ->and(TransferRequest::query()->sole()->summary())->toBe('Department → Sales, Relocation allowance 25,000.00');
});

it('pays the allowances of an approved transfer once, with each component\'s treatment, settled only when the run is finalized', function () {
    $before = PayrollEntry::query()->where('payroll_run_id', tallowCalculate(9)->id)->sole()->only(['gross', 'net_pay', 'taxable_earnings']);
    $transfers = app(TransferRequests::class);
    $request = $transfers->request($this->employee, '2026-09-25', ['department_id' => $this->sales->id], null, null, 'Pune office', $this->preparer,
        [['salary_component_id' => $this->relocation->id, 'amount' => 25000], ['salary_component_id' => $this->packing->id, 'amount' => '5000.50']]);

    // Nothing is paid while the request waits for a decision.
    expect(tallowLines(tallowCalculate(9)))->toHaveCount(0);

    $transfers->approve($request, 'ok', $this->approver);
    $run = tallowCalculate(9);
    [$relocation, $packing] = tallowLines($run)->all();
    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->sole();

    expect($relocation->code)->toBe('RELOC')->and($relocation->type)->toBe('earning')->and((float) $relocation->amount)->toBe(25000.0)->and($relocation->taxable)->toBeTrue()
        ->and($relocation->basis['source'])->toEqual(['key' => 'transfer_allowance', 'ids' => [$request->id]])
        ->and($relocation->basis['transfer_request'])->toBe($request->id)
        ->and($packing->type)->toBe('reimbursement')->and($packing->taxable)->toBeFalse()->and((float) $packing->amount)->toBe(5000.5)
        ->and($entry->inputs['input_sources']['transfer_allowance'])->toBe([$request->id, $request->id])
        ->and(round((float) $entry->gross - (float) $before['gross'], 2))->toBe(25000.0)
        ->and((float) $entry->taxable_earnings)->toBeGreaterThan((float) $before['taxable_earnings'])
        ->and($request->refresh()->allowances_payroll_run_id)->toBeNull();

    // A run of a later month calculated meanwhile leaves the request to the run that carries it.
    expect(tallowLines(tallowCalculate(10)))->toHaveCount(0);

    $this->runs->finalize($this->runs->approve($this->runs->validate($run), $this->approver, 'ok'), $this->approver);
    expect($request->refresh()->allowances_payroll_run_id)->toBe($run->id)->and($request->allowances_paid_at)->not->toBeNull()
        ->and(tallowLines(tallowCalculate(10)))->toHaveCount(0);
});

it('pays a transfer taking effect later with the payroll of its month', function () {
    $transfers = app(TransferRequests::class);
    $request = $transfers->request($this->employee, '2026-10-05', ['department_id' => $this->sales->id], null, null, null, $this->preparer, [['salary_component_id' => $this->relocation->id, 'amount' => 12000]]);
    $transfers->approve($request, null, $this->approver);

    expect(tallowLines(tallowCalculate(9)))->toHaveCount(0)
        ->and(tallowLines(tallowCalculate(10))->pluck('amount')->map(fn ($a) => (float) $a)->all())->toBe([12000.0]);
});
