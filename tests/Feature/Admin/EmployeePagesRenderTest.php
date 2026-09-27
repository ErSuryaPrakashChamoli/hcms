<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\BankAccountsRelationManager;
use App\Filament\Resources\Skills\SkillResource;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->company = Company::factory()->create(['name' => 'Acme']);
    $this->department = Department::factory()->create(['name' => 'Engineering']);
    $this->employee = app(HireEmployeeAction::class)->handle(
        ['first_name' => 'Rahul', 'last_name' => 'Sharma'],
        ['joining_date' => '2025-05-28', 'work_email' => 'rahul@acme.test'],
        ['company_id' => $this->company->id, 'department_id' => $this->department->id],
    );
    $this->admin = tenantUser($this->tenant, ['*']);
    actAsTenant(null);
    $this->actingAs($this->admin);
});

it('renders the employee list, hire wizard, 360 view and edit pages', function () {
    $this->get(EmployeeResource::getUrl('index'))->assertOk()->assertSee('Rahul Sharma')->assertSee('EMP00001');
    $this->get(EmployeeResource::getUrl('create'))->assertOk()->assertSee('Hire employee');
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertOk()
        ->assertSee('Rahul Sharma')->assertSee('Engineering')->assertSee('Timeline')->assertSee('Bank')->assertSee('Statutory');
    $this->get(EmployeeResource::getUrl('edit', ['record' => $this->employee]))->assertOk();
    $this->get(SkillResource::getUrl('index'))->assertOk();
});

it('hides the bank tab and statutory actions from users without sensitive permissions', function () {
    $this->actingAs(tenantUser($this->tenant, ['employee.view']));

    $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertOk()->assertSee('Timeline')->assertDontSee('Bank');
    expect(BankAccountsRelationManager::canViewForRecord($this->employee, ViewEmployee::class))->toBeFalse();
});

it('hires through the wizard', function () {
    actAsTenant($this->tenant);

    Livewire::test(CreateEmployee::class)
        ->fillForm([
            'first_name' => 'Priya',
            'last_name' => 'Nair',
            'joining_date' => '2026-09-01',
            'work_email' => 'priya@acme.test',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'manager_id' => $this->employee->id,
            'audit_reason' => 'Campus hire',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $priya = Employee::query()->where('work_email', 'priya@acme.test')->first();

    expect($priya)->not->toBeNull()
        ->and($priya->employee_code)->toBe('EMP00002')
        ->and($priya->currentManager->manager_id)->toBe($this->employee->id)
        ->and($priya->lifecycle_state)->toBe(LifecycleState::Probation)
        ->and(AuditEvent::query()->where('entity_type', Employee::class)->where('entity_id', (string) $priya->id)->where('action', 'CREATE')->value('reason'))->toBe('Campus hire');
});

it('runs life events from the 360 header', function () {
    actAsTenant($this->tenant);
    $sales = Department::factory()->create(['name' => 'Sales']);

    Livewire::test(ViewEmployee::class, ['record' => $this->employee->getRouteKey()])
        ->callAction('assignPosition', data: ['change_type' => 'transfer', 'effective_from' => '2026-10-01', 'department_id' => $sales->id, 'audit_reason' => 'Move'])
        ->assertHasNoActionErrors()
        ->assertNotified('Position assigned')
        ->callAction('lifecycle', data: ['to_state' => 'confirmed', 'effective_date' => '2026-09-26', 'audit_reason' => 'Cleared'])
        ->assertHasNoActionErrors()
        ->assertNotified('Lifecycle updated');

    $this->employee->refresh();

    expect($this->employee->lifecycle_state)->toBe(LifecycleState::Confirmed)
        ->and($this->employee->positions()->count())->toBe(2)
        ->and($this->employee->positions()->effectiveOn('2026-10-02')->value('department_id'))->toBe($sales->id);
});

it('audits opening the bank tab and revealing an account', function () {
    actAsTenant($this->tenant);
    $account = EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'R', 'bank_name' => 'HDFC', 'account_number' => '50100123456789']);

    Livewire::test(BankAccountsRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])
        ->assertSee('••••6789')
        ->assertDontSee('50100123456789')
        ->callTableAction('reveal', $account, data: ['purpose' => 'Salary transfer check'])
        ->assertHasNoTableActionErrors();

    $views = AuditEvent::query()->where('action', 'VIEW')->where('entity_id', (string) $this->employee->id)->orderBy('id')->get();

    expect($views)->toHaveCount(2)
        ->and($views[0]->metadata['scope'])->toBe('bank_accounts')
        ->and($views[1]->reason)->toBe('Salary transfer check')
        ->and($views[1]->actor_id)->toBe($this->admin->id);
});

it('finds employees through global search', function () {
    $this->get('/admin?'.http_build_query(['search' => 'rahul']))->assertOk();

    $results = EmployeeResource::getGlobalSearchResults('Sharma');
    expect($results->first()->title)->toBe('Rahul Sharma · EMP00001');
});
