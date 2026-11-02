<?php

use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Actions\TransferEmployeeAction;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Integration\Services\JvCodes;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\DimensionValue;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Region;
use App\Domain\Organisation\Services\OrganisationCodes;
use App\Domain\Organisation\Support\DimensionLabel;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\JournalVoucher;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Services\Projects;
use App\Filament\Resources\DimensionValues\DimensionValueResource;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;

require_once __DIR__.'/Di1Helpers.php';

/* Data import DI-1 — the owner decisions D1–D5, decided and built (docs/architecture/data-import.md → Decisions). */

beforeEach(function () {
    $this->travelTo('2026-09-25 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->acme = di1Company();
    $this->eng = Department::factory()->create(['company_id' => $this->acme->id, 'code' => 'ENG', 'name' => 'Engineering']);
    $this->fin = Department::factory()->create(['company_id' => $this->acme->id, 'code' => 'FIN', 'name' => 'Finance']);
    $this->finAp = Department::factory()->create(['company_id' => $this->acme->id, 'code' => 'FIN-AP', 'name' => 'Payables', 'parent_department_id' => $this->fin->id]);
    $this->bfsi = DimensionValue::query()->create(['code' => 'BFSI', 'name' => 'Banking', 'status' => 'active']);
});

it('D1 + D5: puts an employee in a value of the organisation dimension on the position, under the name the organisation gives it', function () {
    $retail = DimensionValue::query()->create(['code' => 'RTL', 'name' => 'Retail', 'status' => 'active']);
    $asha = app(HireEmployeeAction::class)->handle(['first_name' => 'Asha', 'last_name' => 'Rao'], ['joining_date' => '2025-01-01', 'employee_code' => 'EMP1'],
        ['company_id' => $this->acme->id, 'department_id' => $this->eng->id, 'dimension_value_id' => $this->bfsi->id]);
    expect($asha->positions()->first()->dimension_value_id)->toBe($this->bfsi->id);

    // A transfer that names only the department keeps the dimension value; a change of value is its own dated change.
    app(TransferEmployeeAction::class)->handle($asha, ['department_id' => $this->fin->id], '2026-01-01', null, 'Moved to finance');
    $january = EmployeePosition::query()->where('employee_id', $asha->id)->effectiveOn('2026-02-01')->first();
    expect($january->department_id)->toBe($this->fin->id)->and($january->dimension_value_id)->toBe($this->bfsi->id);
    app(AssignPositionAction::class)->handle($asha, ['dimension_value_id' => $retail->id], 'transfer', '2026-03-01', 'Vertical change');
    expect(app(EmployeeRuleContext::class)->build($asha->refresh(), '2026-03-15')['dimension_value_id'])->toBe($retail->id)
        ->and(app(OrganisationCodes::class)->resolvePosition(['dimension_code' => 'rtl']))->toBe(['dimension_value_id' => $retail->id])
        ->and(EmployeePosition::DIMENSIONS['dimension_value_id'])->toBe(['dimensionValue', 'Dimension']);

    // D5: the organisation names its dimension; the screen and the position field follow.
    expect(DimensionLabel::name())->toBe('Dimension')->and(DimensionValueResource::getNavigationLabel())->toBe('Dimension');
    app(SettingsRepository::class)->set(DimensionLabel::SETTING, 'vertical', 'We call them verticals');
    $field = collect(CreateEmployee::positionFields())->first(fn ($f) => $f->getName() === 'dimension_value_id');
    expect(DimensionValueResource::getNavigationLabel())->toBe('Vertical')->and(DimensionValueResource::getPluralModelLabel())->toBe('verticals')
        ->and($field->getLabel())->toBe('Vertical');
    $this->get(DimensionValueResource::getUrl('index'))->assertOk()->assertSee('Verticals');
});

/** A finalized payroll month with entries of [employee, [[code, type, amount], …]]. */
function di1FinalizedRun(array $entries, string $status = 'finalized', int $month = 8): PayrollRun
{
    $company = Company::query()->where('code', 'ACME')->firstOrFail();
    $start = Carbon::create(2026, $month, 1);
    $period = PayrollPeriod::query()->create(['company_id' => $company->id, 'year' => 2026, 'month' => $month, 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->endOfMonth()->toDateString(),
        'payment_date' => $start->copy()->endOfMonth()->toDateString(), 'status' => 'closed']);
    $run = PayrollRun::query()->create(['company_id' => $company->id, 'payroll_period_id' => $period->id, 'status' => $status, 'finalized_at' => '2026-09-01 10:00:00']);
    foreach ($entries as [$employee, $lines]) {
        $entry = PayrollEntry::query()->create(['payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'days_in_period' => 31, 'paid_days' => 31, 'lop_days' => 0, 'gross' => 0, 'total_earnings' => 0,
            'total_deductions' => 0, 'net_pay' => 0, 'employer_cost' => 0, 'taxable_earnings' => 0, 'status' => 'calculated']);
        foreach ($lines as $i => [$code, $type, $amount]) {
            PayrollEntryLine::query()->create(['payroll_entry_id' => $entry->id, 'salary_component_id' => SalaryComponent::query()->where('code', $code)->value('id'), 'code' => $code, 'name' => ucfirst(strtolower($code)),
                'type' => $type, 'classification' => 'other', 'amount' => $amount, 'taxable' => false, 'basis' => [], 'sort_order' => $i]);
        }
    }

    return $run;
}

it('D2: posts a finalized payroll run as one balanced journal voucher by pay component ledgers and department segments', function () {
    $asha = di1Hire($this->acme, 'EMP1');
    $ravi = di1Hire($this->acme, 'EMP2', 'Ravi');
    app(TransferEmployeeAction::class)->handle($asha, ['department_id' => $this->eng->id, 'dimension_value_id' => $this->bfsi->id], '2026-01-01', null, 'Placed');
    app(TransferEmployeeAction::class)->handle($ravi, ['department_id' => $this->finAp->id], '2026-01-01', null, 'Placed');
    $adv = collect(config('peopleos.payroll.defaults'))->firstWhere('code', 'ADV');
    $gratuity = SalaryComponent::query()->create(array_merge($adv, ['code' => 'GRAT_ER', 'name' => 'Gratuity (employer)', 'type' => 'employer_contribution', 'status' => 'active']));
    $jv = app(JvCodes::class);
    $jv->set('department', $this->eng, 'CC-ENG');
    $jv->set('sub_department', $this->finAp, 'CC-FINAP');
    $jv->set('dimension', $this->bfsi, 'V-BFSI');
    $jv->set('pay_component', SalaryComponent::query()->where('code', 'BASIC')->first(), '5100');
    $jv->set('pay_component', SalaryComponent::query()->where('code', 'ADV')->first(), '1400');
    $jv->set('pay_component', $gratuity, '5300');
    $jv->set('pay_component_payable', $gratuity, '2300');
    app(SettingsRepository::class)->set(JournalVoucher::NET_PAY_SETTING, 'SAL-PAY', 'Our salary payable ledger');

    $run = di1FinalizedRun([
        [$asha, [['BASIC', 'earning', 50000], ['HRA', 'earning', 20000], ['PF_EE', 'deduction', 1800], ['ADV', 'deduction', 1000], ['GRAT_ER', 'employer_contribution', 2405]]],
        [$ravi, [['BASIC', 'earning', 30000], ['PF_EE', 'deduction', 1800]]],
    ]);
    $lines = app(JournalVoucher::class)->lines($run);
    $book = collect($lines)->mapWithKeys(fn (array $l) => [$l['account'].'|'.$l['segment'].'|'.($l['debit'] !== '' ? 'Dr' : 'Cr') => $l['debit'] ?: $l['credit']])->all();
    $expected = [
        '5100|CC-ENG|Dr' => '50000.00', '5100|CC-FINAP|Dr' => '30000.00', 'HRA|CC-ENG|Dr' => '20000.00', '5300|CC-ENG|Dr' => '2405.00',
        'PF_EE||Cr' => '3600.00', '1400||Cr' => '1000.00', '2300||Cr' => '2405.00', 'SAL-PAY||Cr' => '95400.00',
    ];
    ksort($book);
    ksort($expected);
    expect($book)->toBe($expected)
        ->and(collect($lines)->sum(fn ($l) => (float) ($l['debit'] ?: 0)))->toBe(collect($lines)->sum(fn ($l) => (float) ($l['credit'] ?: 0)))
        ->and($lines[0]['narration'])->toStartWith('Payroll Aug 2026 · ');

    // Split by the organisation's own dimension instead: a person without a value has no segment.
    app(SettingsRepository::class)->set(JournalVoucher::SEGMENT_SETTING, 'dimension', 'Post by vertical');
    $byDimension = collect(app(JournalVoucher::class)->lines($run))->filter(fn ($l) => $l['account'] === '5100')->pluck('segment')->sort()->values()->all();
    expect($byDimension)->toBe(['', 'V-BFSI']);

    $csv = iterator_to_array(Reader::fromString(app(JournalVoucher::class)->csv($run))->getRecords(), false);
    expect($csv[0])->toBe(['Account', 'Segment', 'Debit', 'Credit', 'Narration'])->and(count($csv))->toBe(count($lines) + 1)
        ->and(app(JournalVoucher::class)->fileName($run))->toBe('journal-voucher-2026-08.csv');

    $draft = di1FinalizedRun([[$asha, [['BASIC', 'earning', 100]]]], 'calculated', 7);
    expect(fn () => app(JournalVoucher::class)->lines($draft))->toThrow(RuntimeException::class, 'A journal voucher is made from finalized payroll only');
});

it('D3: lets an uploader with only the create permission add masters, but not change the ones on file', function () {
    Region::query()->create(['code' => 'NORTH', 'name' => 'North', 'status' => 'active']);
    $this->actingAs(tenantUser($this->tenant, ['employee.import', 'organisation.create']));
    $import = di1Stage('regions', "Code,Name\nSOUTH,South\nNORTH,North zone\n");
    expect(di1Rows($import)[1]['action'])->toBe('create')
        ->and(di1Rows($import)[2]['errors'])->toBe(['This row changes a record on file, which needs the organisation.update permission; you may only add new records.']);

    $this->actingAs(tenantUser($this->tenant, ['employee.import', 'people_setup.create']));
    $levels = di1Stage('levels', "Code,Name,Rank\nL1,Associate,1\nM1,Manager,7\n");
    expect(di1Rows($levels)[1]['errors'][0])->toContain('people_setup.update')->and(di1Rows($levels)[2]['action'])->toBe('create');

    $this->actingAs(tenantUser($this->tenant, ['employee.import', 'organisation.create', 'organisation.update']));
    $both = di1Stage('regions', "Code,Name\nNORTH,North zone\n");
    di1Run($both);
    expect(Region::query()->where('code', 'NORTH')->value('name'))->toBe('North zone')->and(Level::query()->where('code', 'M1')->exists())->toBeFalse();
});

it('D4: puts only current or joining employees on a project from a file, as the screen does, and keeps those already on it', function () {
    $asha = di1Hire($this->acme, 'EMP1');
    $gone = di1Hire($this->acme, 'EMP2', 'Ravi');
    $project = Project::query()->create(['code' => 'OLD', 'name' => 'Old project', 'manager_employee_id' => $gone->id]);
    app(Projects::class)->addMember($project, $gone);
    forceLifecycle($gone, LifecycleState::Exited, ['exit_date' => '2026-06-30']);
    // The rule lives in the domain: the service and the project itself refuse someone who has left.
    expect(fn () => app(Projects::class)->addMember(Project::query()->create(['code' => 'X', 'name' => 'X']), $gone))->toThrow(RuntimeException::class, 'EMP2 has left or never joined')
        ->and(fn () => Project::query()->create(['code' => 'Y', 'name' => 'Y', 'manager_employee_id' => $gone->id]))->toThrow(RuntimeException::class, 'a project manager is a current or joining employee');

    $import = di1Stage('projects', "Code,Name,Manager code,Member codes\nNEW,New project,EMP2,EMP1;EMP2\nOLD,Old project,EMP2,EMP2;EMP1\n");
    $rows = di1Rows($import);
    expect($rows[1]['errors'])->toBe([
        "Manager code 'EMP2' has left or never joined: a project manager is a current or joining employee.",
        "Member code 'EMP2' has left or never joined: only current or joining employees are put on a project.",
    ])
        ->and($rows[2]['action'])->toBe('update')->and($rows[2]['errors'])->toBeNull();
    di1Run($import);
    expect($project->members()->pluck('employee_id')->sort()->values()->all())->toBe([$asha->id, $gone->id]);
    expect(ImportTypes::service('projects')->format()['columns'][6][2])->toContain('current or joining employee');
});
