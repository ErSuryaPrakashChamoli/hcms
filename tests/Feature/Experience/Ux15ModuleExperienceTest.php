<?php

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Filament\Resources\Departments\DepartmentResource;
use App\Filament\Resources\Departments\Pages\CreateDepartment;
use App\Filament\Resources\Departments\Pages\EditDepartment;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Support\PeopleOsText;
use Filament\Facades\Filament;
use Livewire\Livewire;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| UX.15 closure P1-01: every module page is composed from the PeopleOS experience layer. Lists open with what
| the viewer can see and what matters (a narrowing-only lens); people in tables are person chips; record forms
| end in a review; details name the record. Counts come from each table's own query, so the context can never
| show more than the table may.
*/

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    $this->type = LeaveType::query()->where('code', 'EL')->first();
    $this->manager = employeeWithUser(null, ['leave.view', 'leave.approve', 'employee.view']);
    $this->report = tap(employeeWithUser($this->manager, ['leave.apply']), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    $this->stranger = tap(employeeWithUser(null, ['leave.apply']), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    $this->leave = fn (Employee $e, string $status, string $from = '2026-10-20') => LeaveRequest::query()->create([
        'employee_id' => $e->id, 'leave_type_id' => $this->type->id, 'from_date' => $from, 'to_date' => $from, 'from_session' => 'full', 'to_session' => 'full',
        'days' => 1, 'reason' => 'Synthetic', 'status' => $status,
    ]);
});

it('opens every resource list with the PeopleOS context, no "List" breadcrumb and bounded page sizes', function () {
    $this->actingAs(tenantUser($this->tenant, ['*']));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $rendered = 0;
    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        if (! $resource::hasPage('index') || ! rescue(fn () => $resource::canAccess(), false, false)) {
            continue;
        }
        $html = $this->get($resource::getUrl('index'))->assertOk()->getContent();
        expect($html)->toMatch('/you can see[ .·]/u', $resource)
            ->and($html)->not->toContain('fi-breadcrumbs')
            ->and($html)->toContain(e(PeopleOsText::sentence((string) $resource::getTitleCasePluralModelLabel())));
        $rendered++;
    }
    expect($rendered)->toBeGreaterThan(130);
});

it('counts exactly what the table can show, for a scoped manager as for everyone else', function () {
    ($this->leave)($this->report, 'pending');
    ($this->leave)($this->report, 'approved', '2026-10-27');
    ($this->leave)($this->stranger, 'pending');
    app(AccessScopes::class)->assign($this->manager->user, ['company' => [Company::factory()->create()->id]], 'Reporting line only');
    $this->actingAs($this->manager->user);

    $page = Livewire::test(ListLeaveRequests::class)->instance();
    $context = $page->moduleContext();

    expect($context['total'])->toBe($page->getTableRecords()->total())->toBe(2)
        ->and(collect($context['lenses'])->pluck('count', 'key')->all())->toBe(['pending' => 1, 'approved' => 1])
        ->and($context['sentence'])->toStartWith('2 leave requests you can see');
});

it('lets the lens only narrow, and never to a status the viewer cannot see here', function () {
    ($this->leave)($this->report, 'pending');
    ($this->leave)($this->report, 'approved', '2026-10-27');
    $this->actingAs(tenantUser($this->tenant, ['leave.view', 'employee.view']));

    $page = Livewire::test(ListLeaveRequests::class)->call('setPosLens', 'approved');
    expect($page->instance()->getTableRecords()->pluck('status')->unique()->values()->all())->toBe(['approved'])
        ->and($page->get('posLens'))->toBe('approved');

    $page->call('setPosLens', 'cancelled');
    expect($page->get('posLens'))->toBeNull()->and($page->instance()->getTableRecords()->total())->toBe(2);
});

it('shows people in module tables as person chips and points pending requests to the Approval Center', function () {
    ($this->leave)($this->report, 'pending');
    $this->actingAs($this->manager->user);

    $this->get(LeaveRequestResource::getUrl('index'))->assertOk()
        ->assertSee('data-person="'.$this->report->id.'"', false)
        ->assertSee('decision waiting', false)
        ->assertSee('Decide in the Approval Center');
});

it('turns record forms into a flow: context, review, and a button that names the change', function () {
    $admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($admin);
    $department = Department::query()->create(['company_id' => Company::query()->first()?->id ?? Company::factory()->create()->id, 'name' => 'Engineering', 'code' => 'ENG', 'status' => 'active', 'effective_from' => '2024-04-01']);

    $this->get(DepartmentResource::getUrl('create'))->assertOk()
        ->assertSee('New department')->assertSee('Create department')
        ->assertSee("posFormReview('create')", false)->assertSee('You review the details before creating');

    $edit = $this->get(DepartmentResource::getUrl('edit', ['record' => $department]))->assertOk()
        ->assertSee("posFormReview('edit')", false)
        ->assertSee('Active · in effect since 1 Apr 2024')->assertSee('Your changes are listed for review before you save');
    expect(Livewire::test(EditDepartment::class, ['record' => $department->id])->instance()->getBreadcrumbs())
        ->toBe([DepartmentResource::getUrl('index') => 'Departments', 'Engineering']);
    expect(Livewire::test(CreateDepartment::class)->instance()->getTitle())->toBe('New department');
});

it('words empty and filtered lists honestly', function () {
    $this->actingAs(tenantUser($this->tenant, ['leave.view', 'employee.view']));
    $page = Livewire::test(ListLeaveRequests::class)->instance();

    expect($page->getTable()->getEmptyStateHeading())->toBe('No leave requests yet')
        ->and($page->getTable()->getEmptyStateDescription())->toBe('Leave requests you can see will appear here.')
        ->and($page->moduleContext()['sentence'])->toBe('Nothing here yet that you can see.')
        ->and($page->getTable()->getPaginationPageOptions())->toBe([10, 25, 50, 100]);
});
