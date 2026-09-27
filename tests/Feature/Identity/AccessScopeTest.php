<?php

use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Location;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Users\Pages\EditUser;
use Livewire\Livewire;

/*
 | Phase 0.2 ABAC matrix: company / location scopes enforced at the query layer (UI, search,
 | exports, services), by policies (record level), and not at all for system contexts.
 */

function hireAt(Company $company, Location $location, string $first, ?Department $department = null): Employee
{
    return app(HireEmployeeAction::class)->handle(
        ['first_name' => $first, 'last_name' => 'Person'],
        ['joining_date' => '2025-01-01'],
        ['company_id' => $company->id, 'location_id' => $location->id, 'department_id' => $department?->id],
    );
}

beforeEach(function () {
    $this->tenant = provisionTenant('Tenant A');
    $this->tenantB = provisionTenant('Tenant B');
    actAsTenant($this->tenant);

    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);

    $this->companyA = Company::factory()->create(['name' => 'Alpha Co']);
    $this->companyB = Company::factory()->create(['name' => 'Beta Co']);
    $this->delhi = Location::factory()->create(['company_id' => $this->companyA->id, 'name' => 'Delhi']);
    $this->mumbai = Location::factory()->create(['company_id' => $this->companyA->id, 'name' => 'Mumbai']);
    $this->delhiB = Location::factory()->create(['company_id' => $this->companyB->id, 'name' => 'Delhi (Beta)']);
    $this->engineering = Department::factory()->create(['company_id' => $this->companyA->id, 'name' => 'Engineering']);

    $this->empDelhi = hireAt($this->companyA, $this->delhi, 'Dev', $this->engineering);
    $this->empMumbai = hireAt($this->companyA, $this->mumbai, 'Mum');
    $this->empBeta = hireAt($this->companyB, $this->delhiB, 'Bet');

    $this->scopedHr = tenantUser($this->tenant, ['*']);
    app(AccessScopes::class)->assign($this->scopedHr, ['company' => [$this->companyA->id], 'location' => [$this->delhi->id]], 'Delhi HR');

    $this->companyHr = tenantUser($this->tenant, ['*']);
    app(AccessScopes::class)->assign($this->companyHr, ['company' => [$this->companyA->id]]);

    actAsTenant($this->tenantB);
    $this->companyOther = Company::factory()->create(['name' => 'Other tenant Co']);
    $this->empOther = hireAt($this->companyOther, Location::factory()->create(['company_id' => $this->companyOther->id, 'name' => 'Delhi']), 'Oth');
    actAsTenant($this->tenant);
});

it('case 1: a company+location scoped user reaches only that company and location', function () {
    $this->actingAs($this->scopedHr);

    expect(Employee::query()->pluck('id')->all())->toBe([$this->empDelhi->id]);
    expect(Company::query()->pluck('name')->all())->toBe(['Alpha Co']);
    expect(Location::query()->pluck('name')->all())->toBe(['Delhi']);
    expect(Department::query()->pluck('name')->all())->toBe(['Engineering']);

    expect($this->scopedHr->can('view', $this->empDelhi))->toBeTrue()
        ->and($this->scopedHr->can('view', $this->empMumbai))->toBeFalse()
        ->and($this->scopedHr->can('update', $this->empMumbai))->toBeFalse()
        ->and($this->scopedHr->can('view', $this->empBeta))->toBeFalse()
        ->and($this->scopedHr->can('view', $this->companyB))->toBeFalse()
        ->and($this->scopedHr->can('view', $this->mumbai))->toBeFalse();
});

it('case 2: a company scoped user reaches every location of that company but not another company', function () {
    $this->actingAs($this->companyHr);

    expect(Employee::query()->pluck('id')->sort()->values()->all())->toBe(collect([$this->empDelhi->id, $this->empMumbai->id])->sort()->values()->all());
    expect(Location::query()->pluck('name')->sort()->values()->all())->toBe(['Delhi', 'Mumbai']);
    expect($this->companyHr->can('view', $this->empMumbai))->toBeTrue()
        ->and($this->companyHr->can('view', $this->empBeta))->toBeFalse();
});

it('case 3: a tenant administrator without scope rows sees the whole tenant and never another tenant', function () {
    $this->actingAs($this->admin);

    expect(Employee::query()->count())->toBe(3)->and(Company::query()->count())->toBe(2);
    expect(Employee::query()->find($this->empOther->id))->toBeNull();
    expect($this->admin->can('view', $this->empOther))->toBeFalse();
});

it('case 4: scope cannot be bypassed with record ids, table search, exports or the employee-linked models', function () {
    $this->actingAs($this->scopedHr);

    // Direct URL / IDOR: the record outside the scope is not found rather than merely hidden.
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->empDelhi]))->assertOk();
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->empMumbai]))->assertNotFound();
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->empBeta]))->assertNotFound();

    // Table, table search and global search all run on the resource query, which is scoped.
    Livewire::test(ListEmployees::class)
        ->assertCanSeeTableRecords([$this->empDelhi])
        ->assertCanNotSeeTableRecords([$this->empMumbai, $this->empBeta]);
    expect(EmployeeResource::getEloquentQuery()->where('employee_code', $this->empMumbai->employee_code)->exists())->toBeFalse()
        ->and(EmployeeResource::getGlobalSearchEloquentQuery()->pluck('id')->all())->toBe([$this->empDelhi->id]);

    // Export through the reporting dataset.
    $dataset = app(DatasetRegistry::class)->get('employees');
    expect($dataset->query()->count())->toBe(1);

    // Employee-linked records: a ticket raised for the Mumbai employee is invisible.
    $category = TicketCategory::query()->first();
    $this->actingAs($this->admin);
    $ticket = app(ServiceDesk::class)->open($this->empMumbai, $category, 'Mumbai request', 'Please help');
    $this->actingAs($this->scopedHr);
    expect(Ticket::query()->find($ticket->id))->toBeNull()
        ->and($this->scopedHr->can('view', $ticket))->toBeFalse();
});

it('case 5: system contexts (no authenticated user) stay tenant-bound but are not user-scoped', function () {
    auth()->logout();

    expect(Employee::query()->count())->toBe(3);
    expect(Employee::query()->find($this->empOther->id))->toBeNull();

    actAsTenant(null);
    expect(Employee::query()->count())->toBe(0);
});

it('lets a scoped user still reach their own employee record', function () {
    $selfUser = tenantUser($this->tenant, ['employee.view']);
    $this->actingAs($this->admin);
    $self = app(HireEmployeeAction::class)->handle(['first_name' => 'Self', 'last_name' => 'Person'], ['joining_date' => '2025-01-01', 'user_id' => $selfUser->id], ['company_id' => $this->companyA->id, 'location_id' => $this->mumbai->id]);
    app(AccessScopes::class)->assign($selfUser, ['location' => [$this->delhi->id]]);

    $this->actingAs($selfUser);
    expect(Employee::query()->pluck('id')->sort()->values()->all())->toBe(collect([$this->empDelhi->id, $self->id])->sort()->values()->all());
});

it('audits scope assignments and exposes them on the user form', function () {
    expect(AuditEvent::query()->where('entity_type', UserAccessScope::class)->where('action', 'CREATE')->count())->toBe(3);

    $this->actingAs($this->admin);
    Livewire::test(EditUser::class, ['record' => $this->scopedHr->id])
        ->assertFormSet(fn (array $state) => expect(array_map('intval', $state['access_scope']['company']))->toBe([$this->companyA->id])->and(array_map('intval', $state['access_scope']['location']))->toBe([$this->delhi->id]))
        ->fillForm(['access_scope' => ['company' => [$this->companyA->id], 'location' => [$this->delhi->id, $this->mumbai->id]], 'audit_reason' => 'Now covers Mumbai too'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(UserAccessScope::query()->where('user_id', $this->scopedHr->id)->where('dimension', 'location')->pluck('scope_id')->map(fn ($v) => (int) $v)->sort()->values()->all())
        ->toBe(collect([$this->delhi->id, $this->mumbai->id])->sort()->values()->all());
    expect(AuditEvent::query()->where('entity_type', UserAccessScope::class)->where('reason', 'Now covers Mumbai too')->exists())->toBeTrue();

    $this->actingAs($this->scopedHr);
    expect(Employee::query()->count())->toBe(2);
});

it('lets a scoped manager reach direct reports outside the organisation scope (relationship scope, ADR-0004)', function () {
    $managerUser = tenantUser($this->tenant, ['employee.view']);
    $this->actingAs($this->admin);
    $manager = app(HireEmployeeAction::class)->handle(['first_name' => 'Boss', 'last_name' => 'Person'], ['joining_date' => '2025-01-01', 'user_id' => $managerUser->id], ['company_id' => $this->companyA->id, 'location_id' => $this->delhi->id]);
    $report = app(HireEmployeeAction::class)->handle(['first_name' => 'Rep', 'last_name' => 'Person'], ['joining_date' => '2025-01-01'], ['company_id' => $this->companyA->id, 'location_id' => $this->mumbai->id], $manager->id);
    app(AccessScopes::class)->assign($managerUser, ['location' => [$this->delhi->id]]);

    $this->actingAs($managerUser);
    expect(Employee::query()->pluck('id')->sort()->values()->all())->toBe(collect([$this->empDelhi->id, $manager->id, $report->id])->sort()->values()->all())
        ->and($managerUser->can('view', $report))->toBeTrue()
        ->and($managerUser->can('view', $this->empMumbai))->toBeFalse();
});
