<?php

use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Models\WorkforceBudget;
use App\Domain\Workforce\Models\WorkforcePlanVersion;
use App\Domain\Workforce\Models\WorkforceScenario;
use App\Domain\Workforce\Services\WorkforceBudgets;
use App\Domain\Workforce\Services\WorkforcePlans;
use App\Domain\Workforce\Services\WorkforceScenarios;
use App\Filament\Pages\PositionHierarchyPage;
use App\Filament\Pages\TeamWorkforce;
use App\Filament\Pages\VacanciesPage;
use App\Filament\Pages\WorkforceAnalyticsPage;
use App\Filament\Pages\WorkforceDashboard;
use App\Filament\Pages\WorkforceSnapshotsPage;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Positions\Pages\ListPositions;
use App\Filament\Resources\Positions\Pages\ViewPosition;
use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\WorkforceBudgets\Pages\ManageWorkforceBudgets;
use App\Filament\Resources\WorkforceBudgets\WorkforceBudgetResource;
use App\Filament\Resources\WorkforcePlans\Pages\ManageWorkforcePlans;
use App\Filament\Resources\WorkforcePlans\WorkforcePlanResource;
use App\Filament\Resources\WorkforceScenarios\Pages\ManageWorkforceScenarios;
use App\Filament\Resources\WorkforceScenarios\WorkforceScenarioResource;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Workforce/WorkforceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->approver = tenantUser($this->tenant, ['workforce.view', 'workforce.approve', 'workforce.manage', 'workforce.costs']);
    $this->org = workforceOrg();
    $this->manager = activeEmployee(null, ['workforce.team']);
    $this->employee = activeEmployee($this->manager, ['career.self']);
    $designation = Designation::query()->create(['name' => 'Team Lead', 'code' => 'TL']);
    $this->lead = openPosition(['code' => 'TL-1', 'designation_id' => $designation->id, 'organisation_node_id' => $this->org['department']->id], $this->admin, $this->approver, '2026-01-01');
    $this->member = openPosition(['code' => 'DEV-1', 'title' => 'Developer', 'organisation_node_id' => $this->org['department']->id, 'parent_position_id' => $this->lead->id], $this->admin, $this->approver, '2026-01-01');
    app(AssignPositionAction::class)->handle($this->manager, ['position_id' => $this->lead->id], 'transfer', '2026-02-01', 'Seat');
    app(WorkforceScenarios::class)->create('growth', 'Growth 2027', null, ['attrition_rate_percent' => 6], $this->admin);
    $plan = app(WorkforcePlans::class)->create(['code' => 'ENG27', 'name' => 'Engineering 2027', 'organisation_node_id' => $this->org['department']->id, 'period_type' => 'annual', 'period_start' => '2027-01-01', 'period_end' => '2027-12-31'], $this->admin);
    app(WorkforcePlans::class)->addLine($plan->versions()->first(), ['movement_type' => 'new_position', 'headcount' => 2, 'effective_date' => '2027-03-01'], $this->admin);
    app(WorkforceBudgets::class)->create(['name' => 'Engineering 2027', 'company_id' => $this->org['company']->id, 'period_start' => '2027-01-01', 'period_end' => '2027-12-31', 'cost_basis' => 'employer_cost', 'amount' => 1000000], $this->admin);
    actAsTenant(null);
});

it('renders the workforce screens for workforce planners', function () {
    $this->get(WorkforceDashboard::getUrl())->assertOk()->assertSee('Approved seats')->assertSee('Vacant seats');
    $this->get(PositionResource::getUrl('index'))->assertOk()->assertSee('TL-1')->assertSee('DEV-1');
    $this->get(PositionResource::getUrl('view', ['record' => $this->lead]))->assertOk()->assertSee('Team Lead')->assertSee('Requirements')->assertSee('filled');
    $this->get(PositionHierarchyPage::getUrl())->assertOk()->assertSee('TL-1')->assertSee('DEV-1');
    $this->get(VacanciesPage::getUrl())->assertOk()->assertSee('DEV-1');
    $this->get(WorkforcePlanResource::getUrl('index'))->assertOk()->assertSee('ENG27');
    $this->get(WorkforceScenarioResource::getUrl('index'))->assertOk()->assertSee('Growth 2027')->assertSee('planning assumption', false);
    $this->get(WorkforceBudgetResource::getUrl('index'))->assertOk()->assertSee('Engineering 2027');
    $this->get(WorkforceSnapshotsPage::getUrl())->assertOk()->assertSee('Approved seats');
    $this->get(WorkforceAnalyticsPage::getUrl())->assertOk()->assertSee('Planned vs actual');
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->manager]))->assertOk();
});

it('keeps workforce planning from employees and shows managers only their team positions', function () {
    $this->actingAs($this->employee->user);
    foreach ([WorkforceDashboard::getUrl(), PositionResource::getUrl('index'), WorkforcePlanResource::getUrl('index'), WorkforceScenarioResource::getUrl('index'), WorkforceBudgetResource::getUrl('index'), VacanciesPage::getUrl(), WorkforceSnapshotsPage::getUrl(), TeamWorkforce::getUrl()] as $url) {
        expect($this->get($url)->status())->toBe(403, $url);
    }

    $this->actingAs($this->manager->user);
    $this->get(TeamWorkforce::getUrl())->assertOk()->assertSee('DEV-1')->assertDontSee('ENG27');
    $this->get(PositionResource::getUrl('index'))->assertOk()->assertSee('DEV-1');
    foreach ([WorkforcePlanResource::getUrl('index'), WorkforceBudgetResource::getUrl('index'), WorkforceScenarioResource::getUrl('index'), WorkforceDashboard::getUrl()] as $url) {
        expect($this->get($url)->status())->toBe(403, $url);
    }
});

it('runs the main workforce actions from the screens through the domain services', function () {
    actAsTenant($this->tenant);
    Livewire::test(ListPositions::class)->callAction('create', data: ['code' => 'QA-1', 'title' => 'QA engineer', 'organisation_node_id' => $this->org['department']->id, 'effective_from' => '2026-10-05'])
        ->assertHasNoActionErrors()->assertNotified('Position QA-1 created as a draft');
    $qa = Position::query()->where('code', 'QA-1')->sole();
    Livewire::test(ViewPosition::class, ['record' => $qa->id])->callAction('move_proposed', data: [])->assertNotified('Position is now proposed');
    $this->actingAs($this->approver);
    Livewire::test(ViewPosition::class, ['record' => $qa->id])->callAction('move_approved', data: [])->assertNotified('Position is now approved');
    Livewire::test(ViewPosition::class, ['record' => $qa->id])->callAction('move_open', data: [])->assertNotified('Position is now open');
    Livewire::test(ViewPosition::class, ['record' => $qa->id])->callAction('move_frozen', data: ['reason' => 'Budget hold'])->assertNotified('Position is now frozen');
    Livewire::test(ViewPosition::class, ['record' => $this->member->id])->callAction('change', data: ['effective_from' => '2026-11-01', 'title' => 'Senior developer', 'reason' => 'Scope'])->assertNotified('New version recorded');
    expect($qa->refresh()->status)->toBe('frozen')->and($this->member->refresh()->title)->toBe('Senior developer');

    $this->actingAs($this->admin);
    $version = WorkforcePlanVersion::query()->sole();
    Livewire::test(ManageWorkforcePlans::class)->mountTableAction('lines', $version)->assertHasNoTableActionErrors();
    Livewire::test(ManageWorkforcePlans::class)->callTableAction('submit', $version)->assertNotified('Submitted for review');
    $this->actingAs($this->approver);
    Livewire::test(ManageWorkforcePlans::class)->callTableAction('review', $version->refresh())->assertNotified('Under review');
    Livewire::test(ManageWorkforcePlans::class)->callTableAction('approve', $version->refresh(), data: ['note' => 'OK'])->assertNotified('Plan version approved');
    Livewire::test(ManageWorkforcePlans::class)->callTableAction('publish', $version->refresh(), data: [])->assertNotified('Plan version is active');
    Livewire::test(ManageWorkforcePlans::class)->mountTableAction('forecast', $version->refresh())->assertHasNoTableActionErrors();
    Livewire::test(ManageWorkforceScenarios::class)->callTableAction('approve', WorkforceScenario::query()->sole())->assertNotified('Scenario approved');
    Livewire::test(ManageWorkforceBudgets::class)->callTableAction('approve', WorkforceBudget::query()->sole())->assertNotified('Budget approved');
    Livewire::test(ManageWorkforceBudgets::class)->mountTableAction('compare', WorkforceBudget::query()->sole())->assertHasNoTableActionErrors();
    expect($version->refresh()->status)->toBe('active');

    // Employee 360: occupy a seat through the existing "Transfer / promote" action.
    $this->actingAs($this->admin);
    Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])
        ->callAction('assignPosition', data: ['change_type' => 'transfer', 'effective_from' => '2026-10-05', 'position_id' => $this->member->id, 'audit_reason' => 'Joins the team'])
        ->assertHasNoActionErrors()->assertNotified('Position assigned');
    expect($this->employee->positions()->effectiveOn('2026-10-05')->first()->position_id)->toBe($this->member->id);
});
