<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Filament\Pages\People;
use Livewire\Livewire;

/*
| UX.15.10: people feel like people. The directory groups by department, location or manager, shows a
| table with peekable person chips, and a "Recently changed" view built from the viewer's own feed.
*/

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $company = Company::factory()->create();
    $eng = Department::factory()->create(['name' => 'Engineering']);
    $ops = Department::factory()->create(['name' => 'Operations']);
    $hire = fn (string $first, Department $d, ?Employee $m = null) => tap(app(HireEmployeeAction::class)->handle(
        ['first_name' => $first, 'last_name' => 'Dir'], ['joining_date' => '2024-01-01'], ['company_id' => $company->id, 'department_id' => $d->id], $m?->id,
    ), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    $this->lead = $hire('Lata', $eng);
    $this->dev = $hire('Dev', $eng, $this->lead);
    $this->ops = $hire('Om', $ops);
    $this->hr = tenantUser($this->tenant, ['employee.view']);
    EmployeeTimelineEntry::query()->create(['employee_id' => $this->dev->id, 'occurred_on' => now()->subDay(), 'category' => 'position', 'title' => 'Moved to Platform']);
    EmployeeTimelineEntry::query()->create(['employee_id' => $this->ops->id, 'occurred_on' => now()->subDay(), 'category' => 'compensation', 'title' => 'Salary revised']);
    actAsTenant(null);
});

it('groups the directory and shows people as peekable chips in the table', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->hr);

    Livewire::test(People::class)->call('setGroup', 'department')->call('setDisplay', 'list')
        ->assertSee('Engineering')->assertSee('Operations')
        ->assertSeeHtml('data-person="'.$this->dev->id.'"')
        ->call('setGroup', 'nonsense')->assertSet('group', null);
});

it('lists recently changed people from the viewer\'s own feed, without hidden categories', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->hr);

    $page = Livewire::test(People::class)->call('setDisplay', 'changed')->assertSee('Moved to Platform');
    expect($page->instance()->changed->pluck('person_id')->all())->toContain($this->dev->id)->not->toContain($this->ops->id);
    $page->assertDontSee('Salary revised');
});
