<?php

use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Filament\Pages\PayrollControlRoom;
use App\Filament\Resources\ComplianceRules\ComplianceRuleResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\CompensationRelationManager;
use App\Filament\Resources\PayrollAdjustments\PayrollAdjustmentResource;
use App\Filament\Resources\PayrollRuns\Pages\ViewPayrollRun;
use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Resources\Payslips\PayslipResource;
use App\Filament\Resources\SalaryComponents\SalaryComponentResource;
use App\Filament\Resources\SalaryStructures\SalaryStructureResource;
use App\Filament\Resources\StatutoryProfiles\StatutoryProfileResource;
use App\Filament\Resources\TaxDeclarations\TaxDeclarationResource;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->admin);
    $this->company = payrollCompany();
    $this->employee = salariedEmployee(600000, ['payroll.payslip', 'task.view']);
    EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    $this->run = app(PayrollRuns::class)->open($this->company, 2026, 9, $this->admin);
    actAsTenant(null);
});

it('renders the payroll pages and the salary tab', function () {
    $this->get(PayrollControlRoom::getUrl())->assertOk()->assertSee('Employees without a salary')->assertSee($this->company->name);
    $this->get(PayrollRunResource::getUrl('index'))->assertOk()->assertSee('Sep 2026');
    $this->get(PayrollRunResource::getUrl('view', ['record' => $this->run]))->assertOk()->assertSee('Calculate');
    $this->get(SalaryComponentResource::getUrl('index'))->assertOk()->assertSee('Special allowance');
    $this->get(SalaryStructureResource::getUrl('index'))->assertOk()->assertSee('Standard structure');
    $this->get(PayrollAdjustmentResource::getUrl('index'))->assertOk();
    $this->get(PayslipResource::getUrl('index'))->assertOk();
    $this->get(StatutoryProfileResource::getUrl('index'))->assertOk()->assertSee('Karnataka');
    $this->get(ComplianceRuleResource::getUrl('index'))->assertOk()->assertSee('Employees Provident Fund');
    $this->get(TaxDeclarationResource::getUrl('index'))->assertOk();
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertOk();

    // Phase 11: the tab is Compensation; it proposes changes and never writes salary directly.
    Livewire::test(CompensationRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])
        ->assertOk()->assertSee('600,000.00')->assertSee('Propose compensation change')->assertDontSee('Assign / revise salary');
});

it('drives the pipeline from the run page and shows the employee only their own payslip', function () {
    actAsTenant($this->tenant);
    Livewire::test(ViewPayrollRun::class, ['record' => $this->run->id])->callAction('calculate')->assertNotified();
    Livewire::test(ViewPayrollRun::class, ['record' => $this->run->id])->callAction('validate')->assertNotified();
    expect($this->run->refresh()->status)->toBe('validated');

    // The preparer cannot approve their own run.
    Livewire::test(ViewPayrollRun::class, ['record' => $this->run->id])->callAction('approve')->assertNotified('Not allowed');

    $this->actingAs($this->approver);
    Livewire::test(ViewPayrollRun::class, ['record' => $this->run->id])->callAction('approve')->assertNotified('Run approved');
    Livewire::test(ViewPayrollRun::class, ['record' => $this->run->id])->callAction('finalize')->assertNotified('Run finalized; payslips generated');
    expect(PayrollRun::query()->find($this->run->id)->status)->toBe('finalized')->and(Payslip::query()->count())->toBe(1);

    $other = salariedEmployee(300000, ['payroll.payslip']);
    $payslip = Payslip::query()->first();
    actAsTenant(null);
    $this->actingAs($this->employee->user);
    $this->get(PayslipResource::getUrl('index'))->assertOk()->assertSee($payslip->number);
    $this->get(PayslipResource::getUrl('view', ['record' => $payslip]))->assertOk()->assertSee('Net pay');
    $this->get(PayrollRunResource::getUrl('index'))->assertForbidden();
    $this->get(SalaryComponentResource::getUrl('index'))->assertForbidden();

    $this->actingAs($other->user);
    $this->get(PayslipResource::getUrl('index'))->assertOk()->assertDontSee($payslip->number);
    $this->get(PayslipResource::getUrl('view', ['record' => $payslip]))->assertNotFound(); // scoped out of the query entirely
});
