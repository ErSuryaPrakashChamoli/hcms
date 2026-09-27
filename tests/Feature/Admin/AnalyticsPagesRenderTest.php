<?php

use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Analytics\Models\Report;
use App\Filament\Pages\DashboardViewer;
use App\Filament\Pages\WorkforceCommandCentre;
use App\Filament\Resources\Dashboards\DashboardResource;
use App\Filament\Resources\Reports\Pages\CreateReport;
use App\Filament\Resources\Reports\Pages\ViewReport;
use App\Filament\Resources\Reports\ReportResource;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->employee = activeEmployee(null, ['analytics.view', 'task.view']);
    $this->report = Report::query()->where('name', 'Headcount by department')->first();
    actAsTenant(null);
});

it('renders the analytics pages', function () {
    $this->get(WorkforceCommandCentre::getUrl())->assertOk()->assertSee('Headcount')->assertSee('Critical skills');
    $this->get(DashboardViewer::getUrl())->assertOk()->assertSee('HR overview')->assertSee('Needs attention');
    $this->get(ReportResource::getUrl('index'))->assertOk()->assertSee('Headcount by department');
    $this->get(ReportResource::getUrl('view', ['record' => $this->report]))->assertOk()->assertSee('Grouped results');
    $this->get(ReportResource::getUrl('create'))->assertOk();
    $this->get(ReportResource::getUrl('edit', ['record' => $this->report]))->assertOk();
    $this->get(DashboardResource::getUrl('index'))->assertOk()->assertSee('hr-overview');
    $this->get(DashboardResource::getUrl('edit', ['record' => Dashboard::query()->first()]))->assertOk();

    $this->actingAs($this->employee->user);
    $this->get(DashboardViewer::getUrl())->assertOk();
    $this->get(WorkforceCommandCentre::getUrl())->assertForbidden();
    $this->get(DashboardResource::getUrl('index'))->assertForbidden();
});

it('builds a report through the form and exports it', function () {
    actAsTenant($this->tenant);
    Livewire::test(CreateReport::class)
        ->fillForm(['name' => 'Engineers', 'dataset' => 'employees', 'definition' => ['fields' => ['employee_code', 'name', 'department'], 'filters' => [['field' => 'department', 'operator' => 'equals', 'value' => 'Engineering']], 'visualization' => ['type' => 'table']]])
        ->call('create')
        ->assertHasNoFormErrors();
    $report = Report::query()->where('name', 'Engineers')->first();
    expect($report)->not->toBeNull()->and($report->owner_id)->toBe($this->admin->id)->and($report->def('filters.0.operator'))->toBe('equals');

    Livewire::test(ViewReport::class, ['record' => $report->id])->assertOk()->callAction('export')->assertFileDownloaded();
    expect($report->runs()->count())->toBe(1);
});
