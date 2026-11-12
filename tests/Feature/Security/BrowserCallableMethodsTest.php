<?php

use App\Domain\Analytics\Models\Report;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Filament\Pages\Birthdays;
use App\Filament\Pages\OrganisationMap;
use App\Filament\Pages\People;
use App\Filament\Widgets\ReportChart;
use Filament\Facades\Filament;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| QA security: Livewire lets the browser call any public method of a component and sends back what it returns
| ($wire.method(...) in the console), filling model-typed parameters from any id it is given. View helpers written for
| a page's Blade template must therefore not be public, or must check the record themselves; otherwise an employee
| reads, through a page they may open, what that page itself never shows them.
*/

/** What the browser receives when it calls the method, JSON-encoded as sent; null when the call is refused. */
function browserCall(Testable $component, string $method, mixed ...$params): ?string
{
    try {
        $component->call($method, ...$params);
    } catch (MethodNotFoundException) {
        return null;
    }

    return json_encode(data_get($component->effects, 'returns.0'));
}

/** A colleague with private details: a personal e-mail and a birthday they chose not to share. */
function privateColleague(string $first, ?Employee $manager = null): Employee
{
    $employee = app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Private'], ['joining_date' => '2024-01-01'],
        ['company_id' => Company::query()->orderBy('id')->firstOrFail()->id], $manager?->id);
    $employee->person->forceFill(['personal_email' => strtolower($first).'.home@example.test', 'birthday_month' => 3, 'birthday_day' => 14, 'birthday_shared' => false])->save();

    return forceLifecycle($employee, LifecycleState::Active, ['joining_date' => '2024-01-01'])->refresh();
}

beforeEach(function () {
    $this->travelTo('2026-11-11 10:00:00');
    $this->tenant = provisionTenant('Alpha');
    actAsTenant($this->tenant);
    Company::factory()->create();
    $panel = Filament::getPanel('admin');
    $panel->boot();
    Filament::setCurrentPanel($panel);
    $this->manager = privateColleague('Meera');
    $this->stranger = privateColleague('Rohan');
    // An ordinary employee: their own record, the birthdays page, nothing about anyone else.
    $this->employee = forceLifecycle(employeeWithUser($this->manager, ['employee.self', 'celebration.view']), LifecycleState::Active, ['joining_date' => '2025-01-01']);
    $this->actingAs($this->employee->user);
});

it('does not hand an employee the records of everyone through the organisation map', function () {
    $sent = browserCall(Livewire::test(OrganisationMap::class), 'nodes', [$this->manager->id, $this->stranger->id]);

    expect($sent === null || (! str_contains($sent, 'rohan.home@example.test') && ! str_contains($sent, 'meera.home@example.test')))->toBeTrue();
});

it('does not hand a directory viewer the full records behind the people cards', function () {
    $sent = browserCall(Livewire::test(People::class), 'grouped');

    expect($sent === null || ! str_contains($sent, 'meera.home@example.test'))->toBeTrue();
});

it('does not tell an employee the birthday of someone who chose not to share it', function () {
    $component = Livewire::test(Birthdays::class);

    expect(browserCall($component, 'dateOf', $this->stranger->id))->toBeNull()
        ->and(browserCall($component, 'nextOf', $this->stranger->id))->toBeNull()
        ->and(browserCall($component, 'anniversaryLine', $this->stranger->id, 'month'))->toBeNull();
});

it('keeps a report chart on a report its viewer may see', function () {
    $mine = Report::query()->where('name', 'Headcount by department')->firstOrFail();
    $mine->forceFill(['is_shared' => true])->save();
    $secret = $mine->replicate()->forceFill(['name' => 'Confidential exits review', 'owner_id' => tenantUser($this->tenant, ['*'])->id, 'is_shared' => false]);
    $secret->save();
    $viewer = tenantUser($this->tenant, ['analytics.view', 'employee.view']);
    $this->actingAs($viewer);
    expect($viewer->can('view', $mine))->toBeTrue()->and($viewer->can('view', $secret))->toBeFalse();

    $chart = Livewire::test(ReportChart::class, ['reportId' => $mine->id])->assertSee('Headcount by department');
    try {
        $chart->set('reportId', $secret->id)->assertDontSee('Confidential exits review');
    } catch (CannotUpdateLockedPropertyException) {
        // Refused outright: the property is locked.
    }

    // Even when mounted on it, a report the viewer may not see is not shown.
    Livewire::test(ReportChart::class, ['reportId' => $secret->id])->assertDontSee('Confidential exits review');
});
