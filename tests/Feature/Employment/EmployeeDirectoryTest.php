<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Location;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\TimelineRelationManager;
use Livewire\Livewire;

/* Phase 1 §50–§52: directory search and filters run on scoped queries; timeline hides classified entries. */

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = Company::factory()->create();
    $this->delhi = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Delhi']);
    $this->mumbai = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Mumbai']);
    $this->sales = Department::factory()->create(['company_id' => $this->company->id, 'name' => 'Sales']);
    $this->boss = app(HireEmployeeAction::class)->handle(['first_name' => 'Bhavna', 'last_name' => 'Boss'], ['joining_date' => '2024-01-01'], ['company_id' => $this->company->id, 'location_id' => $this->delhi->id]);
    $this->priya = app(HireEmployeeAction::class)->handle(['first_name' => 'Priya', 'last_name' => 'Nair', 'preferred_name' => 'Pri'], ['joining_date' => '2025-01-01', 'work_email' => 'priya@x.test'], ['company_id' => $this->company->id, 'location_id' => $this->delhi->id, 'department_id' => $this->sales->id], $this->boss->id);
    $this->rahul = app(HireEmployeeAction::class)->handle(['first_name' => 'Rahul', 'last_name' => 'Iyer'], ['joining_date' => '2025-01-01'], ['company_id' => $this->company->id, 'location_id' => $this->mumbai->id]);
});

it('searches by name, preferred name, code and email and filters by location, department and manager', function () {
    Livewire::test(ListEmployees::class)
        ->searchTable('Pri')->assertCanSeeTableRecords([$this->priya])->assertCanNotSeeTableRecords([$this->rahul])
        ->searchTable('Iyer')->assertCanSeeTableRecords([$this->rahul])->assertCanNotSeeTableRecords([$this->priya])
        ->searchTable($this->boss->employee_code)->assertCanSeeTableRecords([$this->boss])
        ->searchTable('priya@x.test')->assertCanSeeTableRecords([$this->priya])
        ->searchTable('')
        ->filterTable('location', $this->mumbai->id)->assertCanSeeTableRecords([$this->rahul])->assertCanNotSeeTableRecords([$this->priya, $this->boss])
        ->resetTableFilters()
        ->filterTable('department', $this->sales->id)->assertCanSeeTableRecords([$this->priya])->assertCanNotSeeTableRecords([$this->rahul])
        ->resetTableFilters()
        ->filterTable('manager', $this->boss->id)->assertCanSeeTableRecords([$this->priya])->assertCanNotSeeTableRecords([$this->rahul, $this->boss]);
});

it('keeps search and filters inside the organisation scope', function () {
    $delhiHr = tenantUser($this->tenant, ['*']);
    app(AccessScopes::class)->assign($delhiHr, ['location' => [$this->delhi->id]]);
    $this->actingAs($delhiHr);

    Livewire::test(ListEmployees::class)
        ->assertCanSeeTableRecords([$this->priya, $this->boss])->assertCanNotSeeTableRecords([$this->rahul])
        ->searchTable('Rahul')->assertCanNotSeeTableRecords([$this->rahul])
        ->searchTable('')
        ->filterTable('location', $this->mumbai->id)->assertCanNotSeeTableRecords([$this->rahul]);
});

it('hides compensation timeline entries from users without the sensitive permission', function () {
    app(Timeline::class)->record($this->priya, 'compensation', 'Hire: salary assigned', '2025-01-01', null, null, ['structure' => 'STANDARD']);
    app(Timeline::class)->record($this->priya, 'position', 'Joined Sales', '2025-01-01');

    $viewer = tenantUser($this->tenant, ['employee.view']);
    $this->actingAs($viewer);
    Livewire::test(TimelineRelationManager::class, ['ownerRecord' => $this->priya, 'pageClass' => ViewEmployee::class])
        ->assertCanSeeTableRecords($this->priya->timelineEntries()->where('category', 'position')->get())
        ->assertCanNotSeeTableRecords($this->priya->timelineEntries()->where('category', 'compensation')->get());

    $this->actingAs($this->hr);
    Livewire::test(TimelineRelationManager::class, ['ownerRecord' => $this->priya, 'pageClass' => ViewEmployee::class])
        ->assertCanSeeTableRecords($this->priya->timelineEntries()->where('category', 'compensation')->get());
});

it('shows the overview snapshot with manager and tenure on the Employee 360', function () {
    $this->travelTo('2026-09-21');
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->priya]))
        ->assertOk()->assertSee('Bhavna Boss')->assertSee('Delhi')->assertSee('year');
    expect(Employee::query()->count())->toBe(3);
});
