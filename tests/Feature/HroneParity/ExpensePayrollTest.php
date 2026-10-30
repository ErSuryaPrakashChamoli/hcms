<?php

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Services\Exits;
use App\Domain\Exit\Services\FinalSettlements;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseAdvanceEntry;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Services\ExpenseAdvances;
use App\Domain\Expense\Services\ExpenseCategories;
use App\Domain\Expense\Services\ExpenseReceipts;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Expense\Settlement\ExpenseSettlementSource;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\PayExpenses;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* HROne setup parity EX-E — expense money through payroll and the full and final settlement: approved reports paid with
 * payroll (with or without the payment step), advances paid out and recovered through payroll, everything left settled at
 * exit; lines carry their source ids, records settle only when the run is finalized, nothing is added without records,
 * the engine version is unchanged and nothing is paid or recovered twice. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['payroll.*', 'employee.*', 'expense.*', 'exit.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
    $this->employee = salariedEmployee(600000, ['expense.claim', 'exit.resign']);
    $this->user = $this->employee->user;
    EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    $this->cab = app(ExpenseCategories::class)->save(null, ['type' => 'amount', 'name' => 'Cab', 'code' => 'CAB']);
    exePolicy('expense', ['categories' => [['category' => $this->cab->id]]]);
    $this->reports = app(ExpenseReports::class);
    $this->advances = app(ExpenseAdvances::class);
    $this->runs = app(PayrollRuns::class);
});

function exePolicy(string $type, array $settings, array $only = []): Policy
{
    $policy = Policy::create(['type' => $type, 'name' => Str::title($type).' '.uniqid(), 'code' => 'EXE_'.strtoupper(uniqid())]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    $conditions = $only === [] ? [] : [['field' => 'employee_id', 'operator' => 'in', 'value' => array_map(fn (Employee $e) => $e->id, $only)]];
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'Rule', 'conditions' => $conditions, 'priority' => $only === [] ? 1000 : 50]);

    return $policy;
}

/** A report of one expense, submitted by the employee and approved in full by HR. */
function exeApprovedReport(ExpenseCategory $category, Employee $employee, User $as, User $approver, string $date, float $amount): ExpenseReport
{
    $reports = app(ExpenseReports::class);
    $receipt = app(ExpenseReceipts::class)->capture($employee, ['expense_category_id' => $category->id, 'expense_date' => $date, 'amount' => $amount], $as);
    $report = $reports->submit($reports->create($employee, 'Trip '.$date, [$receipt->id], $as), $as);
    $reports->approvePart($report->parts()->sole(), null, null, $approver);

    return $report->refresh();
}

function exeCalculate(int $month): PayrollRun
{
    $runs = app(PayrollRuns::class);
    $open = PayrollRun::query()->whereHas('period', fn ($q) => $q->where('year', 2026)->where('month', $month))->whereNotIn('status', ['finalized', 'paid'])->first();

    return $runs->calculate($open ?? $runs->open(test()->company, 2026, $month, test()->hr), test()->hr);
}

function exeFinalize(PayrollRun $run): PayrollRun
{
    $runs = app(PayrollRuns::class);

    return $runs->finalize($runs->approve($runs->validate($run), test()->approver, 'ok'), test()->approver);
}

function exeLine(PayrollRun $run, string $code, ?Employee $employee = null): ?PayrollEntryLine
{
    return PayrollEntryLine::query()->whereHas('entry', fn ($q) => $q->where('payroll_run_id', $run->id)->where('employee_id', ($employee ?? test()->employee)->id))->where('code', $code)->first();
}

function exeSetting(string $key, mixed $value): void
{
    app(SettingsRepository::class)->set($key, $value, 'test');
}

it('keeps today\'s behaviour by default: approved reports wait on Pay expenses and payroll adds nothing', function () {
    expect(config('peopleos.settings')['expense.payment.step_required'])->toBeTrue()
        ->and(config('peopleos.settings')['expense.exit.pending_reports'])->toBe('ignore')
        ->and(collect(config('peopleos_setup.modules.expense.settings'))->pluck('key')->all())->toContain('expense.payment.step_required', 'expense.exit.pending_reports');

    $baseline = exeCalculate(9);
    $before = PayrollEntry::query()->where('payroll_run_id', $baseline->id)->sole();
    expect($before->inputs['input_sources'] ?? null)->toBeNull()->and($baseline->calculation_version)->toBe(PayrollCalculator::VERSION)->and(PayrollCalculator::VERSION)->toBe('payroll-2.5');

    $report = exeApprovedReport($this->cab, $this->employee, $this->user, $this->hr, '2026-09-15', 1200);
    expect($report->status)->toBe('approved')->and((float) $report->net_payable)->toBe(1200.0)->and($report->payment_channel)->toBeNull()
        ->and($report->paymentStatus())->toBe('Waiting for payment');

    $run = exeCalculate(9);
    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->sole();
    expect(exeLine($run, 'EXPENSE'))->toBeNull()->and($entry->inputs['input_sources'] ?? null)->toBeNull()
        ->and((float) $entry->net_pay)->toBe((float) $before->net_pay)->and((float) $entry->gross)->toBe((float) $before->gross);
});

it('pays a report scheduled from Pay expenses with the next payroll, non-taxable and outside gross, settled only when the run is finalized', function () {
    $report = exeApprovedReport($this->cab, $this->employee, $this->user, $this->hr, '2026-09-15', 1200);
    $before = PayrollEntry::query()->where('payroll_run_id', exeCalculate(9)->id)->sole()->only(['gross', 'taxable_earnings', 'net_pay', 'total_deductions']);

    // Only payers in scope schedule a payment, never their own.
    $manager = tenantUser($this->tenant, ['expense.approve', 'expense.view']);
    expect(fn () => $this->reports->scheduleForPayroll($report, $manager))->toThrow(ExpenseRuleViolation::class, 'You cannot record payments for this report.')
        ->and(fn () => $this->reports->scheduleForPayroll($report, $this->user))->toThrow(ExpenseRuleViolation::class, 'You cannot record payments for this report.');

    Livewire::test(PayExpenses::class)->assertOk()->assertCanSeeTableRecords([$report])->callTableAction('payWithPayroll', $report)->assertHasNoTableActionErrors();
    $report->refresh();
    expect($report->payment_channel)->toBe('payroll')->and($report->payment_scheduled_by)->toBe($this->hr->id)->and($report->paymentStatus())->toBe('Scheduled for payroll')
        ->and(fn () => $this->reports->scheduleForPayroll($report, $this->hr))->toThrow(ExpenseRuleViolation::class, 'already scheduled for payroll');

    $run = exeCalculate(9);
    $line = exeLine($run, 'EXPENSE');
    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->sole();
    expect($line)->not->toBeNull()->and((float) $line->amount)->toBe(1200.0)->and($line->type)->toBe('reimbursement')->and($line->taxable)->toBeFalse()
        ->and($line->basis['source'])->toEqual(['key' => 'expense_report', 'ids' => [$report->id]])->and($line->basis['reports'])->toBe([$report->number])
        ->and($run->calculation_version)->toBe('payroll-2.5')
        ->and((float) $entry->gross)->toBe((float) $before['gross'])->and((float) $entry->taxable_earnings)->toBe((float) $before['taxable_earnings'])
        ->and((float) $entry->total_deductions)->toBe((float) $before['total_deductions'])->and(round((float) $entry->net_pay - (float) $before['net_pay'], 2))->toBe(1200.0);

    // The run carries it: it is not paid outside payroll as well, and it is not paid until the run is final.
    expect(fn () => $this->reports->markPaid($report, 'UTR-1', '2026-09-21', $this->hr))->toThrow(ExpenseRuleViolation::class, 'already being paid in the payroll of');
    $run = $this->runs->approve($this->runs->validate($run), $this->approver, 'ok');
    expect($report->refresh()->paid_on)->toBeNull();
    $run = $this->runs->finalize($run, $this->approver);

    $report->refresh();
    expect($report->status)->toBe('paid')->and($report->payment_channel)->toBe('payroll')->and($report->payroll_run_id)->toBe($run->id)
        ->and($report->paid_on->toDateString())->toBe(($run->period->payment_date ?? $run->period->end_date)->toDateString())
        ->and($report->payment_reference)->toBe('Payroll '.$run->period->label())->and($report->paymentStatus())->toBe('Paid with payroll');

    // Never twice: the next run has nothing for it, and Pay expenses no longer lists it.
    $this->travelTo('2026-10-25 09:00:00');
    expect(exeLine(exeCalculate(10), 'EXPENSE'))->toBeNull();
    Livewire::test(PayExpenses::class)->assertCanNotSeeTableRecords([$report]);
});

it('sends approved reports straight to payroll when there is no payment step, and Pay expenses may still pay them until a run carries them', function () {
    exeSetting('expense.payment.step_required', false);
    $first = exeApprovedReport($this->cab, $this->employee, $this->user, $this->hr, '2026-09-10', 700);
    $second = exeApprovedReport($this->cab, $this->employee, $this->user, $this->hr, '2026-09-11', 300);
    expect($first->payment_channel)->toBe('payroll')->and($first->payment_scheduled_at)->not->toBeNull()->and($first->payment_scheduled_by)->toBeNull();

    // Paid outside payroll before any run includes it: it leaves payroll.
    $this->reports->markPaid($second, 'UTR-2', '2026-09-21', $this->hr);
    expect($second->refresh()->payment_channel)->toBe('bank')->and($second->payment_scheduled_at)->toBeNull();

    $run = exeCalculate(9);
    expect((float) exeLine($run, 'EXPENSE')->amount)->toBe(700.0)->and(exeLine($run, 'EXPENSE')->basis['source']['ids'])->toBe([$first->id]);
    exeFinalize($run);
    expect($first->refresh()->status)->toBe('paid')->and($second->refresh()->payment_reference)->toBe('UTR-2');

    // A rejected report has nothing to pay and never reaches payroll.
    $reports = app(ExpenseReports::class);
    $receipt = app(ExpenseReceipts::class)->capture($this->employee, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-09-12', 'amount' => 50], $this->user);
    $rejected = $reports->submit($reports->create($this->employee, 'Rejected', [$receipt->id], $this->user), $this->user);
    $reports->rejectPart($rejected->parts()->sole(), 'Personal ride', $this->hr);
    expect($rejected->refresh()->status)->toBe('rejected')->and($rejected->payment_channel)->toBeNull();
});

it('pays out advances approved for payroll, recovers what is left after the recovery date, and leaves advances dispensed before as they are', function () {
    exePolicy('expense_advance', ['enabled' => true, 'max_open' => 3, 'disbursement' => 'payroll', 'settle_within_days' => 10, 'recovery' => 'payroll', 'recovery_after_days' => 5]);
    $this->travelTo('2026-08-05 09:00:00');
    $advance = $this->advances->approve($this->advances->request($this->employee, ['amount' => 2000, 'purpose' => 'Client visit'], $this->user), null, null, $this->hr);
    expect($advance->disbursement_channel)->toBe('payroll');

    $this->travelTo('2026-08-25 09:00:00');
    $august = exeCalculate(8);
    $payout = exeLine($august, 'EXP_ADVANCE');
    expect((float) $payout->amount)->toBe(2000.0)->and($payout->type)->toBe('reimbursement')->and($payout->taxable)->toBeFalse()
        ->and($payout->basis['source'])->toEqual(['key' => 'expense_advance_payout', 'ids' => [$advance->id]])
        // The run carries the payout: it is neither dispensed outside payroll nor cancelled meanwhile.
        ->and(fn () => $this->advances->dispense($advance->refresh(), 'UTR-1', '2026-08-25', $this->hr))->toThrow(ExpenseRuleViolation::class, 'already being paid out in the payroll of')
        ->and(fn () => $this->advances->cancel($advance->refresh(), 'Trip off', $this->hr))->toThrow(ExpenseRuleViolation::class, 'cannot be cancelled now')
        ->and($advance->refresh()->status)->toBe('approved');
    $august = exeFinalize($august);

    $advance->refresh();
    $paidOn = ($august->period->payment_date ?? $august->period->end_date)->copy();
    expect($advance->status)->toBe('disbursed')->and($advance->disbursement_channel)->toBe('payroll')->and($advance->disbursed_payroll_run_id)->toBe($august->id)
        ->and($advance->disbursed_on->toDateString())->toBe($paidOn->toDateString())->and((float) $advance->outstanding)->toBe(2000.0)
        ->and($advance->settle_by->toDateString())->toBe($paidOn->copy()->addDays(10)->toDateString())
        ->and($advance->recovery_due_on->toDateString())->toBe($paidOn->copy()->addDays(15)->toDateString())
        ->and(ExpenseAdvanceEntry::query()->where('expense_advance_id', $advance->id)->sole()->only(['kind', 'payroll_run_id']))->toBe(['kind' => 'disbursed', 'payroll_run_id' => $august->id]);

    // An advance dispensed outside payroll before EX-E has no recovery date: payroll leaves it alone.
    $this->travelTo('2026-09-20 09:00:00');
    $older = $this->advances->approve($this->advances->request($this->employee, ['amount' => 600, 'purpose' => 'Visa'], $this->user), null, null, $this->hr);
    $this->advances->dispense($older, 'UTR-OLD', '2026-09-20', $this->hr);
    expect($older->refresh()->disbursement_channel)->toBe('bank')->and($older->recovery_due_on)->not->toBeNull();
    ExpenseAdvance::query()->whereKey($older->id)->update(['recovery_due_on' => null]);

    // A report waiting for a decision holds the recovery (its approval settles advances first).
    $reports = app(ExpenseReports::class);
    $receipt = app(ExpenseReceipts::class)->capture($this->employee, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-09-18', 'amount' => 500], $this->user);
    $waiting = $reports->submit($reports->create($this->employee, 'September', [$receipt->id], $this->user), $this->user);
    $this->travelTo('2026-09-25 09:00:00');
    expect(exeLine(exeCalculate(9), 'EXP_ADV_RECOVERY'))->toBeNull();

    $reports->approvePart($waiting->parts()->sole(), null, null, $this->hr);
    expect((float) $waiting->refresh()->advance_adjusted)->toBe(500.0)->and((float) $advance->refresh()->outstanding)->toBe(1500.0);

    $september = exeCalculate(9);
    $recovery = exeLine($september, 'EXP_ADV_RECOVERY');
    expect($recovery)->not->toBeNull()->and((float) $recovery->amount)->toBe(1500.0)->and($recovery->type)->toBe('deduction')
        ->and($recovery->basis['source'])->toEqual(['key' => 'expense_advance_recovery', 'ids' => [$advance->id]])
        ->and(PayrollEntryLine::query()->whereHas('entry', fn ($q) => $q->where('payroll_run_id', $september->id))->where('code', 'EXP_ADV_RECOVERY')->count())->toBe(1)
        // The run recovers it: no refund, write-off or adjustment against another report meanwhile.
        ->and(fn () => $this->advances->refund($advance->refresh(), 100, 'CASH-1', '2026-09-25', $this->hr))->toThrow(ExpenseRuleViolation::class, 'already being recovered in the payroll of')
        ->and(fn () => $this->advances->writeOff($advance->refresh(), 'Left', $this->hr))->toThrow(ExpenseRuleViolation::class, 'cannot be written off now');
    $later = exeApprovedReport($this->cab, $this->employee, $this->user, $this->hr, '2026-09-24', 300);
    expect((float) $later->advance_adjusted)->toBe(300.0)->and((float) $advance->refresh()->outstanding)->toBe(1500.0)
        ->and((float) $older->refresh()->outstanding)->toBe(300.0);

    $september = exeFinalize($september);
    $advance->refresh();
    $entry = ExpenseAdvanceEntry::query()->where('expense_advance_id', $advance->id)->where('kind', 'recovered')->sole();
    expect($advance->status)->toBe('recovered')->and((float) $advance->outstanding)->toBe(0.0)->and($advance->closed_at)->not->toBeNull()
        ->and((float) $entry->amount)->toBe(-1500.0)->and($entry->entry_key)->toBe('payroll-'.$september->id)->and($entry->payroll_run_id)->toBe($september->id)
        ->and($older->refresh()->status)->toBe('disbursed');

    // Never twice: the next run neither pays out nor recovers again.
    $this->travelTo('2026-10-25 09:00:00');
    $october = exeCalculate(10);
    expect(exeLine($october, 'EXP_ADVANCE'))->toBeNull()->and(exeLine($october, 'EXP_ADV_RECOVERY'))->toBeNull();
});

it('does not pay an advance out with payroll in the month the employee leaves; finance dispenses it instead', function () {
    exePolicy('expense_advance', ['enabled' => true, 'disbursement' => 'payroll']);
    $advance = $this->advances->approve($this->advances->request($this->employee, ['amount' => 900, 'purpose' => 'Last trip'], $this->user), null, null, $this->hr);
    Employee::query()->whereKey($this->employee->id)->update(['exit_date' => '2026-09-30']);

    expect(exeLine(exeCalculate(9), 'EXP_ADVANCE'))->toBeNull()->and($advance->refresh()->status)->toBe('approved');
    $this->advances->dispense($advance, 'UTR-X', '2026-09-21', $this->hr);
    expect($advance->refresh()->status)->toBe('disbursed')->and($advance->disbursement_channel)->toBe('bank');
});

it('settles expense money at exit, leaves what the final salary carries to it, and closes everything when the settlement is paid', function () {
    exePolicy('expense_advance', ['enabled' => true, 'max_open' => 3, 'recovery' => 'exit_only']);
    $unscheduled = exeApprovedReport($this->cab, $this->employee, $this->user, $this->hr, '2026-09-10', 800);
    $scheduled = exeApprovedReport($this->cab, $this->employee, $this->user, $this->hr, '2026-09-11', 400);
    $this->reports->scheduleForPayroll($scheduled, $this->hr);
    $advance = $this->advances->approve($this->advances->request($this->employee, ['amount' => 1000, 'purpose' => 'Trip'], $this->user), null, null, $this->hr);
    $this->advances->dispense($advance, 'UTR-A', '2026-09-21', $this->hr);
    expect($advance->refresh()->recovery_due_on)->toBeNull();
    // Not recovered automatically: the settlement leaves it for HR.
    exePolicy('expense_advance', ['enabled' => true, 'max_open' => 3, 'recovery' => 'none'], [$this->employee]);
    $kept = $this->advances->approve($this->advances->request($this->employee, ['amount' => 250, 'purpose' => 'Visa'], $this->user), null, null, $this->hr);
    $this->advances->dispense($kept, 'UTR-B', '2026-09-21', $this->hr);

    $exits = app(Exits::class);
    $case = $exits->initiate($this->employee, 'resignation', 'Moving', '2026-09-21', '2026-10-01', 30, $this->hr);
    $exits->startClearance($case);
    foreach ($case->clearances()->get() as $stage) {
        $exits->markNotApplicable($stage, $this->hr, 'n/a');
    }
    $settlements = app(FinalSettlements::class);
    $settlement = $settlements->calculate($case->refresh(), $this->hr);
    $lines = $settlement->lines()->get();
    $payable = $lines->firstWhere('code', ExpenseSettlementSource::PAYABLE);
    $balance = $lines->firstWhere('code', ExpenseSettlementSource::BALANCE);

    // The final salary (October) already repays the report scheduled for payroll; the settlement pays the other one.
    expect((float) $lines->firstWhere('code', 'SAL_EXPENSE')->amount)->toBe(400.0)
        ->and($payable->type)->toBe('earning')->and((float) $payable->amount)->toBe(800.0)->and(collect($payable->basis['reports'])->pluck('id')->all())->toBe([$unscheduled->id])
        ->and($balance->type)->toBe('deduction')->and((float) $balance->amount)->toBe(1000.0)->and(collect($balance->basis['advances'])->pluck('id')->all())->toBe([$advance->id]);

    // While the settlement carries them nothing else pays or recovers them.
    expect(fn () => $this->reports->markPaid($unscheduled->refresh(), 'UTR-9', '2026-09-21', $this->hr))->toThrow(ExpenseRuleViolation::class, 'full and final settlement of '.$case->number)
        ->and(fn () => $this->reports->markPaid($scheduled->refresh(), 'UTR-9', '2026-09-21', $this->hr))->toThrow(ExpenseRuleViolation::class, 'full and final settlement of '.$case->number)
        ->and(fn () => $this->advances->refund($advance->refresh(), 100, 'CASH-9', '2026-09-21', $this->hr))->toThrow(ExpenseRuleViolation::class, 'full and final settlement of '.$case->number);
    $october = exeCalculate(10);
    expect(PayrollEntry::query()->where('payroll_run_id', $october->id)->where('employee_id', $this->employee->id)->exists())->toBeTrue()
        ->and(exeLine($october, 'EXPENSE'))->toBeNull();

    // Recalculating keeps the same lines (its own lines are not counted as somewhere else).
    $settlement = $settlements->calculate($case->refresh(), $this->hr);
    expect((float) $settlement->lines()->where('code', ExpenseSettlementSource::PAYABLE)->value('amount'))->toBe(800.0);

    $settlements->approve($settlement, $this->hr, 'ok');
    $settlements->markPaid($settlement->refresh(), 'NEFT-9', $this->hr);
    foreach ([$unscheduled, $scheduled] as $report) {
        $report->refresh();
        expect($report->status)->toBe('paid')->and($report->payment_channel)->toBe('settlement')->and($report->final_settlement_id)->toBe($settlement->id)
            ->and($report->payment_reference)->toBe('NEFT-9');
    }
    $entry = ExpenseAdvanceEntry::query()->where('expense_advance_id', $advance->id)->where('kind', 'recovered')->sole();
    expect($advance->refresh()->status)->toBe('recovered')->and((float) $advance->outstanding)->toBe(0.0)
        ->and((float) $entry->amount)->toBe(-1000.0)->and($entry->entry_key)->toBe('fnf-'.$settlement->id)->and($entry->final_settlement_id)->toBe($settlement->id)
        ->and($kept->refresh()->status)->toBe('disbursed')->and((float) $kept->outstanding)->toBe(250.0);

    // Never twice: a later settlement of the same employee and the open payroll have nothing left of it.
    expect(app(ExpenseSettlementSource::class)->linesFor($case->refresh(), $settlement->refresh()))->toBe([])
        ->and(exeLine(exeCalculate(10), 'EXPENSE'))->toBeNull();
});

it('holds the finance clearance for reports waiting for approval only when the organisation says so', function () {
    $reports = app(ExpenseReports::class);
    $receipt = app(ExpenseReceipts::class)->capture($this->employee, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-09-15', 'amount' => 90], $this->user);
    $waiting = $reports->submit($reports->create($this->employee, 'Last trip', [$receipt->id], $this->user), $this->user);

    $exits = app(Exits::class);
    $case = $exits->initiate($this->employee, 'resignation', 'Moving', '2026-09-21', '2026-10-01', 30, $this->hr);
    $exits->startClearance($case);
    $finance = $case->clearances()->where('stage', 'finance')->sole();

    exeSetting('expense.exit.pending_reports', 'block');
    expect(fn () => $exits->clearStage($finance, $this->hr, 'Checked'))->toThrow(RuntimeException::class, 'Expense reports still waiting for approval: '.$waiting->number);
    $reports->approvePart($waiting->parts()->sole(), null, null, $this->hr);
    expect($exits->clearStage($finance->refresh(), $this->hr, 'Checked')->status)->toBe('cleared');

    // The default does not hold it (as before).
    $other = salariedEmployee(400000, ['expense.claim']);
    $receipt = app(ExpenseReceipts::class)->capture($other, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-09-15', 'amount' => 90], $other->user);
    $reports->submit($reports->create($other, 'Open', [$receipt->id], $other->user), $other->user);
    exeSetting('expense.exit.pending_reports', 'ignore');
    $case = $exits->initiate($other, 'resignation', 'Moving', '2026-09-21', '2026-10-01', 30, $this->hr);
    $exits->startClearance($case);
    expect($exits->clearStage($case->clearances()->where('stage', 'finance')->sole(), $this->hr, 'Checked')->status)->toBe('cleared')
        ->and(ExitCase::query()->count())->toBe(2);
});
