<?php

use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Compensation\Services\CompensationCycles;
use App\Filament\Resources\CompensationBudgets\CompensationBudgetResource;
use App\Filament\Resources\CompensationChanges\CompensationChangeResource;
use App\Filament\Resources\CompensationChanges\Pages\ManageCompensationChanges;
use App\Filament\Resources\CompensationCycles\CompensationCycleResource;
use App\Filament\Resources\CompensationCycles\Pages\ViewCompensationCycle;
use App\Filament\Resources\CompensationCycles\RelationManagers\LinesRelationManager;
use App\Filament\Resources\CompensationRanges\CompensationRangeResource;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\CompensationChangesRelationManager;
use App\Filament\Resources\Employees\RelationManagers\CompensationRelationManager;
use App\Filament\Resources\SalaryStructures\Pages\EditSalaryStructure;
use App\Filament\Resources\SalaryStructures\RelationManagers\VersionsRelationManager;
use App\Filament\Resources\SalaryStructures\SalaryStructureResource;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* Phase 11: the compensation screens render for an administrator and drive the services. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->company = payrollCompany();
    $this->employee = salariedEmployee(600000, ['task.view'], '2026-04-01');
    $this->structure = SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail();
});

it('renders structures, versions, ranges, budgets, cycles and the change queue', function () {
    $this->get(SalaryStructureResource::getUrl('index'))->assertOk()->assertSee('Standard structure')->assertSee('v1 since 2000-01-01');
    Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $this->structure, 'pageClass' => EditSalaryStructure::class])->assertOk()->assertSee('Active');
    $this->get(CompensationRangeResource::getUrl('index'))->assertOk();
    $this->get(CompensationBudgetResource::getUrl('index'))->assertOk();
    $this->get(CompensationCycleResource::getUrl('index'))->assertOk();
    $this->get(CompensationChangeResource::getUrl('index'))->assertOk();

    $cycle = app(CompensationCycles::class)->create(['code' => 'AI', 'name' => 'Annual increment', 'cycle_type' => 'annual_increment', 'company_id' => $this->company->id, 'effective_from' => '2027-04-01', 'default_increase_percent' => 5], $this->admin);
    app(CompensationCycles::class)->populate($cycle, $this->admin);
    $this->get(CompensationCycleResource::getUrl('view', ['record' => $cycle]))->assertOk()->assertSee('Annual increment')->assertSee('Populate');
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $cycle->refresh(), 'pageClass' => ViewCompensationCycle::class])->assertOk()->assertSee('630,000.00');
});

it('lets a reviewer act from the change queue and shows the Employee 360 tabs', function () {
    $proposer = tenantUser($this->tenant, ['compensation.propose']);
    $change = app(CompensationChanges::class)->propose($this->employee, ['change_type' => 'annual_increment', 'effective_from' => '2026-10-01', 'salary_structure_id' => $this->structure->id, 'ctc_annual' => 660000, 'reason' => 'Increment'], $proposer);
    app(CompensationChanges::class)->submit($change, $proposer);

    Livewire::test(ManageCompensationChanges::class)->assertOk()->assertSee($this->employee->employee_code)
        ->callTableAction('review', $change)->assertNotified();
    expect(CompensationChange::query()->find($change->id)->status)->toBe('under_review');

    Livewire::test(CompensationRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])->assertOk()->assertSee('600,000.00')->assertSee('Current');
    Livewire::test(CompensationChangesRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])->assertOk()->assertSee('660,000.00');
});
