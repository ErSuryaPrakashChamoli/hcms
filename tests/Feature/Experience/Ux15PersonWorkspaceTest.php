<?php

use App\Domain\Employment\Actions\ChangeManagerAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Experience\Services\PersonWorkspace;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Organisation\Models\Company;
use App\Filament\Resources\Employees\EmployeeResource;

/*
| UX.15.9: the Employee 360 as a person workspace. One person, one lifetime record (a rehire continues
| the same record); relationships of every type; what happens next and what changed, each gated by the
| rule that already governs it; nothing at all for someone who may not open the 360.
*/

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->company = Company::factory()->create();
    $hire = fn (string $first, ?Employee $manager, array $perms = []) => tap(app(HireEmployeeAction::class)->handle(
        ['first_name' => $first, 'last_name' => 'Life'], ['joining_date' => '2023-04-03', 'user_id' => $perms === [] ? null : tenantUser($this->tenant, $perms)->id],
        ['company_id' => $this->company->id], $manager?->id,
    ), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    $this->boss = $hire('Bhavna', null, ['employee.view']);
    $this->person = $hire('Priya', $this->boss, ['leave.apply']);
    $this->mentor = $hire('Meera', null);
    app(ChangeManagerAction::class)->handle($this->person, $this->mentor, 'mentor', now()->subDays(5)->toDateString(), 'test');
    forceLifecycle($this->person, LifecycleState::Probation, ['probation_end_date' => now()->addDays(18)->toDateString()]);
    $this->hr = tenantUser($this->tenant, ['employee.view']);
    actAsTenant(null);
});

it('shows one lifetime record, what is next and every relationship to someone who may open the 360', function () {
    actAsTenant($this->tenant);
    $w = app(PersonWorkspace::class)->for($this->hr, $this->person->refresh());

    expect($w['lifetime']['employments'])->toBe(1)
        ->and($w['now']['probation']['days'])->toBe(18)
        ->and(collect($w['next'])->pluck('title'))->toContain('Probation ends')
        ->and(collect($w['relationships']['up'])->pluck('label', 'name')->all())->toMatchArray(['Bhavna Life' => 'Line manager', 'Meera Life' => 'Mentor']);

    // A rehire continues the same record: the lifetime counts periods of employment, it never duplicates the person.
    EmployeePosition::query()->create(['employee_id' => $this->person->id, 'company_id' => $this->company->id, 'change_type' => 'rehire', 'effective_from' => now()->toDateString()]);
    expect(app(PersonWorkspace::class)->for($this->hr, $this->person)['lifetime'])->toMatchArray(['employments' => 2, 'rehired' => true])
        ->and(Employee::query()->where('person_id', $this->person->person_id)->count())->toBe(1);
});

it('gates every block: nothing without the 360, no hidden categories, no leave dates without leave access', function () {
    actAsTenant($this->tenant);
    EmployeeTimelineEntry::query()->create(['employee_id' => $this->person->id, 'occurred_on' => now(), 'category' => 'compensation', 'title' => 'Salary revised to 9,99,999']);
    EmployeeTimelineEntry::query()->create(['employee_id' => $this->person->id, 'occurred_on' => now(), 'category' => 'reporting', 'title' => 'Mentor assigned']);

    $stranger = tenantUser($this->tenant, ['leave.apply']);
    expect(app(PersonWorkspace::class)->for($stranger, $this->person))->toBe([]);

    $w = app(PersonWorkspace::class)->for($this->hr, $this->person);
    expect(collect($w['changes'])->pluck('title'))->toContain('Mentor assigned')->not->toContain('Salary revised to 9,99,999')
        ->and($w['sections']->has('leave'))->toBeFalse()
        ->and(collect($w['next'])->pluck('title')->filter(fn ($t) => str_contains($t, 'leave')))->toBeEmpty();
});

it('renders the person workspace with its sections and the records below', function () {
    $this->actingAs($this->hr)->get(EmployeeResource::getUrl('view', ['record' => $this->person]))->assertOk()
        ->assertSee('One lifetime record')
        ->assertSee('href="#journey"', false)->assertSee('href="#records"', false)
        ->assertSee('In probation · ends in 18 days')
        ->assertSee('Relationships')->assertSee('Mentor')
        ->assertSee('data-person="'.$this->mentor->id.'"', false)
        ->assertSee('360 overview')
        ->assertDontSee('class="pos-spacebar"', false);
});
