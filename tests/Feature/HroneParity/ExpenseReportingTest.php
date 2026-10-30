<?php

use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Analytics\Models\Report;
use App\Domain\Analytics\Services\AnalyticsDefaults;
use App\Domain\Analytics\Services\Dashboards;
use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Analytics\Services\ReportLibrary;
use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Expense\Services\ExpenseAdvances;
use App\Domain\Expense\Services\ExpenseCategories;
use App\Domain\Expense\Services\ExpenseReceipts;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\Platform\Models\Tenant;
use App\Filament\Pages\ReportLibraryPage;
use App\Filament\Resources\Dashboards\Pages\ListDashboards;
use Illuminate\Support\Str;
use Livewire\Livewire;

/* HROne setup parity EX-G — expense reports and dashboard: datasets for expense receipts, reports (with their payment),
 * advances and the advance ledger, HROne's expense report templates in the report library, and the expense dashboard
 * an analytics manager adds on request. */

beforeEach(function () {
    $this->travelTo('2026-08-25 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = Company::factory()->create();
    [$this->userA, $this->a] = exgPerson($this->tenant, $this->company, ['expense.claim']);
    [$this->userB, $this->b] = exgPerson($this->tenant, $this->company, ['expense.claim']);
    $this->cab = app(ExpenseCategories::class)->save(null, ['type' => 'amount', 'name' => 'Cab', 'code' => 'CAB']);
    $this->car = app(ExpenseCategories::class)->save(null, ['type' => 'mileage', 'name' => 'Car', 'code' => 'CAR', 'rate_per_km' => 10, 'location_input' => 'text']);
    exgPolicy('expense', ['categories' => [['category' => $this->cab->id], ['category' => $this->car->id]]]);
    exgPolicy('expense_advance', ['enabled' => true, 'settle_within_days' => 10, 'recovery' => 'payroll', 'recovery_after_days' => 5]);

    // B: an advance dispensed in August, part recovered through payroll, the rest outstanding past its settle-by date.
    $advances = app(ExpenseAdvances::class);
    $this->advance = $advances->approve($advances->request($this->b, ['amount' => 1000, 'purpose' => 'Site visit'], $this->userB), null, null, $this->hr);
    $advances->dispense($this->advance, 'UTR-ADV', '2026-08-25', $this->hr);
    $advances->recovered([$this->advance->id => 300], 'payroll-77', '2026-08-31', 'Payroll August 2026', 'Recovered in payroll August 2026', 77);

    // A: a report paid with payroll (amount and mileage), one waiting for payment, one waiting for approval.
    $this->travelTo('2026-09-21 10:00:00');
    $reports = app(ExpenseReports::class);
    $capture = fn (array $data) => app(ExpenseReceipts::class)->capture($this->a, $data, $this->userA);
    $decide = function (array $receiptIds, string $title, bool $approve = true) use ($reports) {
        $report = $reports->submit($reports->create($this->a, $title, $receiptIds, $this->userA), $this->userA);
        if ($approve) {
            $reports->approvePart($report->parts()->sole(), null, null, $this->hr);
        }

        return $report->refresh();
    };
    $this->paid = $decide([$capture(['expense_category_id' => $this->cab->id, 'expense_date' => '2026-09-10', 'amount' => 1200, 'merchant' => 'City Cabs'])->id,
        $capture(['expense_category_id' => $this->car->id, 'expense_date' => '2026-09-11', 'distance_km' => 25, 'from_place' => 'Office', 'to_place' => 'Plant'])->id], 'Client week');
    $reports->scheduleForPayroll($this->paid, $this->hr);
    $reports->paidThrough([$this->paid->id], 'payroll', '2026-09-30', 'Payroll September 2026', 99);
    $this->waiting = $decide([$capture(['expense_category_id' => $this->cab->id, 'expense_date' => '2026-09-12', 'amount' => 300])->id], 'Airport');
    $this->pending = $decide([$capture(['expense_category_id' => $this->cab->id, 'expense_date' => '2026-09-13', 'amount' => 50])->id], 'Taxi', false);
});

/** @return array{0: User, 1: Employee} */
function exgPerson(Tenant $tenant, Company $company, array $permissions): array
{
    $user = tenantUser($tenant, $permissions);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'joining_date' => '2025-01-01']);
    EmployeePosition::query()->create(['employee_id' => $employee->id, 'company_id' => $company->id, 'effective_from' => '2025-01-01']);

    return [$user, $employee];
}

function exgPolicy(string $type, array $settings): Policy
{
    $policy = Policy::create(['type' => $type, 'name' => Str::title($type).' '.uniqid(), 'code' => 'EXG_'.strtoupper(uniqid())]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'Rule', 'conditions' => [], 'priority' => 1000]);

    return $policy;
}

/** @return list<array<string, mixed>> */
function exgRows(string $template, User $as): array
{
    $definition = app(ReportLibrary::class)->find($template);

    return app(ReportRunner::class)->execute($definition['dataset'], $definition['definition'], $as)->rows;
}

it('registers the expense datasets for expense.view holders, under Expense & travel, and keeps them inside the viewer\'s scope', function () {
    $registry = app(DatasetRegistry::class);
    foreach (['expense_receipts', 'expense_reports', 'expense_advances', 'expense_advance_ledger'] as $key) {
        expect($registry->has($key))->toBeTrue()->and($registry->get($key)->category())->toBe('Expense & travel')->and($registry->get($key)->permissions())->toBe(['expense.view']);
        // Every field over real rows: no closure touches an unloaded relation.
        expect(app(ReportRunner::class)->execute($key, [], $this->hr)->total)->toBeGreaterThan(0, $key);
    }
    expect(array_keys($registry->groupedOptions($this->hr)))->toContain('Expense & travel');

    $outsider = tenantUser($this->tenant, ['analytics.reports', 'employee.view']);
    expect(array_keys($registry->availableTo($outsider)))->not->toContain('expense_reports')
        ->and(fn () => app(ReportRunner::class)->execute('expense_reports', [], $outsider))->toThrow(RuntimeException::class, 'cannot report')
        ->and(collect(app(ReportLibrary::class)->availableTo($outsider))->keys()->all())->not->toContain('Expense & travel');

    $scoped = tenantUser($this->tenant, ['expense.view', 'analytics.reports']);
    app(AccessScopes::class)->assign($scoped, ['location' => [Location::factory()->create(['company_id' => $this->company->id])->id]]);
    $this->actingAs($scoped);
    foreach (['expense_receipts', 'expense_reports', 'expense_advances', 'expense_advance_ledger'] as $key) {
        expect(app(ReportRunner::class)->execute($key, ['fields' => ['employee_code']], $scoped)->total)->toBe(0, $key);
    }
});

it('serves HROne\'s expense reports from the library: advances, recovery register, payment register, receipts, report details, outstanding advances', function () {
    $library = app(ReportLibrary::class);
    $keys = collect($library->all())->where('category', 'Expense & travel')->pluck('key')->all();
    expect($keys)->toContain('expense_advance_amount', 'expense_advance_recovery_register', 'expense_payment_register', 'expense_receipts_by_amount', 'expense_receipts_by_mileage',
        'expense_receipt_details', 'expense_report_details', 'outstanding_expense_advances');

    $advances = exgRows('expense_advance_amount', $this->hr);
    expect($advances)->toHaveCount(1)->and($advances[0])->toMatchArray(['number' => $this->advance->number, 'amount_base' => 1000.0, 'approved_amount' => 1000.0, 'status' => 'Dispensed', 'paid_channel' => 'By bank', 'outstanding' => 700.0]);

    $recoveries = exgRows('expense_advance_recovery_register', $this->hr);
    expect($recoveries)->toHaveCount(1)->and($recoveries[0])->toMatchArray(['advance' => $this->advance->number, 'amount' => 300.0, 'through' => 'Payroll', 'reference' => 'Payroll August 2026']);

    $register = collect(exgRows('expense_payment_register', $this->hr))->keyBy('number');
    expect($register->keys()->sort()->values()->all())->toBe(collect([$this->paid->number, $this->waiting->number])->sort()->values()->all())
        ->and($register[$this->paid->number])->toMatchArray(['net_payable' => 1450.0, 'payment_status' => 'Paid with payroll', 'payment_channel' => 'With payroll', 'paid_on' => '2026-09-30', 'paid_month' => '2026-09', 'payment_reference' => 'Payroll September 2026'])
        ->and($register[$this->waiting->number])->toMatchArray(['net_payable' => 300.0, 'payment_status' => 'Waiting for payment', 'payment_channel' => null, 'paid_on' => null]);

    expect(collect(exgRows('expense_receipts_by_amount', $this->hr))->pluck('base_amount')->sort()->values()->all())->toBe([50.0, 300.0, 1200.0]);
    $mileage = exgRows('expense_receipts_by_mileage', $this->hr);
    expect($mileage)->toHaveCount(1)->and($mileage[0])->toMatchArray(['category' => 'Car', 'from_place' => 'Office', 'to_place' => 'Plant', 'distance_km' => 25.0, 'rate_per_km' => 10.0, 'base_amount' => 250.0, 'approved_amount' => 250.0, 'report_number' => $this->paid->number]);
    expect(exgRows('expense_receipt_details', $this->hr))->toHaveCount(4);

    $details = collect(exgRows('expense_report_details', $this->hr))->keyBy('number');
    expect($details)->toHaveCount(3)->and($details[$this->paid->number])->toMatchArray(['receipts' => 2, 'total_claimed' => 1450.0, 'total_approved' => 1450.0, 'paid_amount' => 1450.0, 'status' => 'Paid'])
        ->and($details[$this->pending->number])->toMatchArray(['status' => 'Submitted', 'paid_amount' => 0.0]);

    $outstanding = exgRows('outstanding_expense_advances', $this->hr);
    expect($outstanding)->toHaveCount(1)->and($outstanding[0])->toMatchArray(['number' => $this->advance->number, 'recovered' => 300.0, 'outstanding' => 700.0, 'settle_by' => '2026-09-04', 'days_overdue' => 17, 'recovery_due_on' => '2026-09-09']);

    expect(collect(exgRows('expense_reports_pending', $this->hr))->pluck('number')->all())->toBe([$this->pending->number])
        ->and(collect(exgRows('expense_by_category_this_year', $this->hr))->pluck('sum_approved_amount', 'category')->all())->toBe(['Cab' => 1500.0, 'Car' => 250.0])
        ->and(collect(exgRows('expense_paid_by_month', $this->hr))->pluck('sum_paid_amount', 'paid_month')->all())->toBe(['2026-09' => 1450.0]);

    $this->get(ReportLibraryPage::getUrl())->assertOk()->assertSee('Expense payment register')->assertSee('Outstanding expense advance');
    $report = $library->create('expense_payment_register', $this->hr, null, $this->a->employee_code);
    expect($report->dataset)->toBe('expense_reports')->and(app(ReportRunner::class)->run($report, $this->hr)->total)->toBe(2);
});

it('adds the expense dashboard on request for the roles that see expenses, with expense figures restricted to them', function () {
    // Nothing changes at provisioning: the default dashboard and starter reports only.
    expect(Dashboard::query()->where('slug', AnalyticsDefaults::EXPENSE_DASHBOARD)->exists())->toBeFalse()
        ->and(Report::query()->where('is_shared', true)->count())->toBe(6);

    Livewire::test(ListDashboards::class)->assertActionVisible('addExpenseDashboard')->callAction('addExpenseDashboard')->assertHasNoActionErrors();
    $dashboard = Dashboard::query()->where('slug', AnalyticsDefaults::EXPENSE_DASHBOARD)->sole();
    Livewire::test(ListDashboards::class)->assertActionHidden('addExpenseDashboard');
    app(AnalyticsDefaults::class)->seedExpense();
    expect(Dashboard::query()->where('slug', AnalyticsDefaults::EXPENSE_DASHBOARD)->count())->toBe(1)->and(Report::query()->where('is_shared', true)->count())->toBe(9)
        ->and($dashboard->role_ids)->toContain(Role::query()->where('slug', 'expense-admin')->value('id'))
        ->and($dashboard->role_ids)->not->toContain(Role::query()->where('slug', 'employee')->value('id'));

    $analyst = tenantUser($this->tenant, ['analytics.view']);
    expect(app(Dashboards::class)->forUser($analyst)->pluck('slug')->all())->not->toContain(AnalyticsDefaults::EXPENSE_DASHBOARD);
    $analyst->roles()->attach(Role::query()->where('slug', 'expense-admin')->value('id'));
    expect(app(Dashboards::class)->forUser($analyst->fresh())->pluck('slug')->all())->toContain(AnalyticsDefaults::EXPENSE_DASHBOARD);

    $widgets = collect(app(Dashboards::class)->render($dashboard, $this->hr));
    $kpi = fn (string $metric) => $widgets->firstWhere(fn ($w) => ($w['metric']['key'] ?? null) === $metric)['metric'];
    expect($kpi('expense_reports_pending')['value'])->toBe(1)->and($kpi('expense_payable')['value'])->toBe(300.0)->and($kpi('expense_payable')['format'])->toBe('currency')
        ->and($kpi('expense_advances_outstanding')['value'])->toBe(700.0)->and($kpi('expense_advances_overdue')['value'])->toBe(1)
        ->and($widgets->firstWhere('type', 'chart')['result']->chart['labels'])->toBe(['Cab', 'Car'])
        ->and($widgets->every(fn ($w) => $w['error'] === null))->toBeTrue();

    // A dashboard manager without expense.view sees neither the figures nor the rows.
    $manager = tenantUser($this->tenant, ['analytics.manage', 'analytics.view']);
    $this->actingAs($manager);
    $restricted = collect(app(Dashboards::class)->render($dashboard, $manager));
    expect($restricted->firstWhere(fn ($w) => ($w['metric']['key'] ?? null) === 'expense_payable')['metric']['restricted'])->toBeTrue()
        ->and($restricted->firstWhere('type', 'chart')['error'])->toContain('cannot report');
});
