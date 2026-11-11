<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Experience\Services\MySpace;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Contracts\PaidPayReader;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Filament\Pages\MySalaryYtd;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| HROne parity MS-10 (matrix 22 §22.7.1): My salary YTD — PaidPayReader::yearToDate over finalized / paid payroll only
| (never an open run), months in the financial year's order, the component × month grid with totals, arrears and the
| "How it is calculated" panel, reimbursements, the statutory panels (ceilings only from verified rules), own record only.
*/

beforeEach(function () {
    $this->travelTo('2026-03-27 10:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->approver = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = payrollCompany();
    $this->employee = salariedEmployee(600000, config('peopleos.roles.employee.permissions'), from: '2025-04-01');
    $this->colleague = salariedEmployee(900000, config('peopleos.roles.employee.permissions'), from: '2025-04-01');
    foreach ([$this->employee, $this->colleague] as $i => $e) {
        EmployeeStatutoryDetail::query()->updateOrCreate(['employee_id' => $e->id], ['pan' => 'ABCDE123'.$i.'F', 'pf_applicable' => true, 'esic_applicable' => true]);
        EmployeeBankAccount::create(['employee_id' => $e->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '12345678'.$i, 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    }
    // March 2026 (financial year 2025-26), August and September 2026 finalized; October 2026 only calculated.
    $this->march = ms10Finalize($this->company, 2026, 3, $this->hr, $this->approver);
    $this->travelTo('2026-08-27 10:00:00');
    $this->august = ms10Finalize($this->company, 2026, 8, $this->hr, $this->approver);
    $this->travelTo('2026-09-27 10:00:00');
    PayrollAdjustment::create(['employee_id' => $this->employee->id, 'payroll_period_id' => PayrollPeriod::for($this->company, 2026, 9)->id, 'type' => 'arrear', 'name' => 'July increment arrear', 'amount' => 2500, 'taxable' => true, 'note' => 'Revision of July']);
    $this->september = ms10Finalize($this->company, 2026, 9, $this->hr, $this->approver);
    $this->travelTo('2026-10-27 10:00:00');
    $runs = app(PayrollRuns::class);
    $this->october = $runs->calculate($runs->open($this->company, 2026, 10, $this->hr), $this->hr);
});

function ms10Finalize(Company $company, int $year, int $month, User $hr, User $approver): PayrollRun
{
    $runs = app(PayrollRuns::class);
    $run = $runs->calculate($runs->open($company, $year, $month, $hr), $hr);

    return $runs->finalize($runs->approve($runs->validate($run), $approver), $approver);
}

function ms10Entry(PayrollRun $run, Employee $employee): PayrollEntry
{
    return PayrollEntry::query()->with('lines')->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
}

/** @return array<string, mixed>|null the grid row of a line code */
function ms10Row(array $ytd, string $code, string $type = 'earning'): ?array
{
    return collect($type === 'reimbursement' ? $ytd['reimbursement'] : $ytd['salary'][$type])->firstWhere('code', $code);
}

// ---- the read contract ---------------------------------------------------------------------------------------------

it('reads the financial year month by month from finalized or paid payroll only, in the financial year order', function () {
    $ytd = app(PaidPayReader::class)->yearToDate($this->employee, '2026-27');
    $august = ms10Entry($this->august, $this->employee);
    $september = ms10Entry($this->september, $this->employee);

    expect(array_keys($ytd['months']))->toBe(['2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12', '2027-01', '2027-02', '2027-03'])
        ->and($ytd['months']['2026-04'])->toBe('Apr 2026')
        // The calculated (open) October run is never read; March 2026 belongs to 2025-26.
        ->and($ytd['processed'])->toBe(['2026-08', '2026-09'])
        ->and(ms10Row($ytd, 'BASIC')['months']['2026-08'])->toBe((float) $august->amount('BASIC'))
        ->and(ms10Row($ytd, 'BASIC')['months']['2026-10'])->toBe(0.0)
        ->and(ms10Row($ytd, 'BASIC')['total'])->toBe(round((float) $august->amount('BASIC') + (float) $september->amount('BASIC'), 2))
        ->and($ytd['totals']['net']['months']['2026-09'])->toBe((float) $september->net_pay)
        ->and($ytd['totals']['gross']['total'])->toBe(round((float) $august->gross + (float) $september->gross, 2))
        ->and($ytd['totals']['net']['total'])->toBe(round((float) $august->net_pay + (float) $september->net_pay, 2))
        ->and($ytd['days']['2026-08']['in_period'])->toBe((int) $august->days_in_period);

    // The net per month is what the payslip says.
    expect($ytd['totals']['net']['months']['2026-08'])->toBe((float) $august->payslip->get('totals.net'));

    $previous = app(PaidPayReader::class)->yearToDate($this->employee, '2025-26');
    expect($previous['processed'])->toBe(['2026-03'])
        ->and(array_key_first($previous['months']))->toBe('2025-04')
        ->and(ms10Row($previous, 'BASIC')['months']['2026-03'])->toBe((float) ms10Entry($this->march, $this->employee)->amount('BASIC'));

    // Once October is finalized it counts.
    app(PayrollRuns::class)->finalize(app(PayrollRuns::class)->approve(app(PayrollRuns::class)->validate($this->october), $this->approver), $this->approver);
    expect(app(PaidPayReader::class)->yearToDate($this->employee, '2026-27')['processed'])->toBe(['2026-08', '2026-09', '2026-10']);

    expect(fn () => app(PaidPayReader::class)->yearToDate($this->employee, '2026-28'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(PaidPayReader::class)->yearToDate($this->employee, 'all'))->toThrow(InvalidArgumentException::class);
});

it('never reads another employee\'s pay and fails closed outside the tenant', function () {
    $mine = app(PaidPayReader::class)->yearToDate($this->employee, '2026-27');
    $theirs = app(PaidPayReader::class)->yearToDate($this->colleague, '2026-27');

    expect(ms10Row($mine, 'BASIC')['months']['2026-08'])->toBe((float) ms10Entry($this->august, $this->employee)->amount('BASIC'))
        ->and(ms10Row($theirs, 'BASIC')['months']['2026-08'])->toBe((float) ms10Entry($this->august, $this->colleague)->amount('BASIC'))
        ->and(ms10Row($mine, 'BASIC')['total'])->not->toBe(ms10Row($theirs, 'BASIC')['total'])
        // The arrear was the employee's only.
        ->and(collect($theirs['arrears'])->pluck('name')->all())->toBe([]);

    // Another tenant reads nothing of this one; no tenant at all reads nothing.
    $other = provisionTenant('Globex');
    actAsTenant($other);
    expect(app(PaidPayReader::class)->yearToDate($this->employee, '2026-27')['processed'])->toBe([]);
    actAsTenant(null);
    expect(app(PaidPayReader::class)->yearToDate($this->employee, '2026-27')['processed'])->toBe([]);
});

it('lists arrears, separates deductions and employer contributions, and leaves out components hidden from the payslip', function () {
    $ytd = app(PaidPayReader::class)->yearToDate($this->employee, '2026-27');

    expect($ytd['arrears'])->toHaveCount(1)
        ->and($ytd['arrears'][0])->toMatchArray(['month' => '2026-09', 'name' => 'July increment arrear', 'type' => 'earning', 'amount' => 2500.0, 'note' => 'Revision of July'])
        ->and(collect($ytd['salary']['earning'])->firstWhere('arrears', '!=', [])['months']['2026-09'])->toBe(2500.0)
        ->and(ms10Row($ytd, 'PF_EE', 'deduction')['total'])->toBeGreaterThan(0.0)
        ->and(ms10Row($ytd, 'PF_ER', 'employer_contribution')['total'])->toBeGreaterThan(0.0)
        ->and(ms10Row($ytd, 'PF_EE'))->toBeNull();

    // "How it is calculated": the component's set-up and what each month recorded.
    $basic = ms10Row($ytd, 'BASIC');
    expect($basic['settings'])->toMatchArray(['method' => 'formula', 'formula' => 'ctc_monthly * 0.40', 'taxable' => true, 'pf_applicable' => true, 'proratable' => true])
        ->and($basic['detail']['2026-08'])->toHaveKeys(['formula', 'divisor', 'full_month', 'paid_days', 'days_in_period']);

    // A component hidden from the payslip (HROne setup parity PS-1) is not shown here either.
    expect(ms10Row($ytd, 'CONV'))->not->toBeNull();
    SalaryComponent::query()->where('code', 'CONV')->update(['show_on_payslip' => false]);
    $this->travelTo('2026-10-28 10:00:00');
    $runs = app(PayrollRuns::class);
    $runs->finalize($runs->approve($runs->validate($runs->calculate($this->october, $this->hr)), $this->approver), $this->approver);
    $after = app(PaidPayReader::class)->yearToDate($this->employee, '2026-27');
    expect(ms10Row($after, 'CONV')['months']['2026-10'])->toBe(0.0)
        ->and(ms10Row($after, 'CONV')['months']['2026-09'])->toBe(1600.0);
});

it('reports the statutory panels from the lines, with ceilings only from a verified rule version', function () {
    $ytd = app(PaidPayReader::class)->yearToDate($this->employee, '2026-27');
    $august = ms10Entry($this->august, $this->employee);

    expect($ytd['statutory']['pf']['months']['2026-08']['employee'])->toBe((float) $august->amount('PF_EE'))
        ->and($ytd['statutory']['pf']['months']['2026-08']['employer'])->toBe((float) $august->amount('PF_ER'))
        ->and($ytd['statutory']['pf']['totals']['employee'])->toBe(round((float) $august->amount('PF_EE') + (float) ms10Entry($this->september, $this->employee)->amount('PF_EE'), 2))
        ->and($ytd['statutory']['pt']['months']['2026-08']['state'])->toBe('KA')
        // The packs' rules are not verified: no ceiling, never a fallback number.
        ->and($ytd['statutory']['pf']['ceiling'])->toBeNull()
        ->and($ytd['bonus']['rule'])->toBeNull()
        ->and($ytd['overtime']['amount'])->toBe(0.0);

    // A verified EPF version used by payroll gives its own ceiling.
    $epf = app(ComplianceRules::class)->resolve('EPF', '2026-11-30');
    $verified = ComplianceRule::query()->create(['jurisdiction' => 'IN', 'code' => 'EPF', 'state' => null, 'name' => 'EPF verified test', 'version' => (int) ComplianceRule::query()->where('code', 'EPF')->max('version') + 1,
        'effective_from' => '2026-11-01', 'parameters' => $epf->parameters, 'status' => ComplianceRule::ACTIVE]);
    DB::table('compliance_rules')->where('id', $verified->id)->update(['verification_status' => ComplianceRule::VERIFIED]);
    app(ComplianceRules::class)->forget();
    $this->travelTo('2026-11-27 10:00:00');
    $runs = app(PayrollRuns::class);
    $runs->finalize($runs->approve($runs->validate($this->october), $this->approver), $this->approver);
    ms10Finalize($this->company, 2026, 11, $this->hr, $this->approver);

    $after = app(PaidPayReader::class)->yearToDate($this->employee, '2026-27');
    expect($after['statutory']['pf']['months']['2026-11']['rule_id'])->toBe($verified->id)
        ->and($after['statutory']['pf']['ceiling'])->toBe(['value' => (float) $epf->param('wage_ceiling'), 'rule' => $verified->refresh()->label()]);
});

// ---- the page --------------------------------------------------------------------------------------------------------

it('shows the employee their own year with the header, tabs, grid and empty states, audited', function () {
    $this->actingAs($this->employee->user);
    $page = Livewire::test(MySalaryYtd::class)->assertOk()
        ->assertSet('year', '2026-27')
        ->assertSee('(#'.$this->employee->employee_code.')')
        ->assertSee('Basic')->assertSee('Net pay')->assertSee('Arrears paid this year')->assertSee('July increment arrear')
        ->assertDontSee($this->colleague->employee_code);
    expect($page->instance()->years())->toContain('2026-27', '2025-26')
        ->and(AuditEvent::query()->where('module', 'employment')->where('entity_id', (string) $this->employee->id)->get()->contains(fn ($e) => ($e->metadata['purpose'] ?? null) === 'salary-ytd'))->toBeTrue();

    $page->call('setTab', 'statutory')->assertSee('Provident fund')->assertSee('No verified rule')->assertSee('Professional tax');
    $page->call('setTab', 'reimbursement')->assertSee('Reimbursements paid through payroll');
    $page->call('setTab', 'nonsense')->assertSet('tab', 'salary');

    // The previous year; an unknown year falls back to the newest.
    $page->call('setTab', 'salary')->set('year', '2025-26')->assertSet('year', '2025-26')->assertDontSee('July increment arrear');
    $page->set('year', '1999-00')->assertSet('year', '2026-27')->assertSee('July increment arrear');

    // "How it is calculated" for a row of the grid (an open modal re-renders only itself).
    $page->mountAction('how', ['row' => 'earning|BASIC'])->assertMountedActionModalSee(['ctc_monthly * 0.40', 'Dividing factor', 'Prorated on paid days']);

    // Someone without finalized pay this year.
    $newcomer = salariedEmployee(300000, config('peopleos.roles.employee.permissions'), from: '2026-11-01');
    $this->actingAs($newcomer->user);
    Livewire::test(MySalaryYtd::class)->assertOk()->assertSee('SALARY NOT PROCESSED')
        ->call('setTab', 'reimbursement')->assertSee('REIMBURSEMENT NOT PROCESSED');
});

it('opens only for a person with an employee record and the payslip right, and lists the page in My space', function () {
    $this->actingAs($this->employee->user);
    $this->get(MySalaryYtd::getUrl())->assertOk();
    app()->forgetInstance(MySpace::class);
    expect(collect(app(MySpace::class)->available($this->employee->user))->map(fn ($e) => $e['item']->key())->all())->toContain('salary_ytd', 'payslips');

    $noRight = employeeWithUser(null, ['employee.self']);
    $this->actingAs($noRight->user);
    $this->get(MySalaryYtd::getUrl())->assertForbidden();

    $noEmployee = tenantUser($this->tenant, config('peopleos.roles.employee.permissions'));
    $this->actingAs($noEmployee);
    $this->get(MySalaryYtd::getUrl())->assertForbidden();
    expect(app(TenantContext::class)->current()->id)->toBe($this->tenant->id);
});
