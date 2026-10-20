<?php

use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Actions\PromoteEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\WorkforcePulse;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Filament\Pages\WorkforceCommandCentre;
use App\Livewire\Experience\DrawerHost;
use Livewire\Livewire;

/*
| UX.15.12: Workforce pulse tells the workforce as a story from real figures, and every figure drills
| down to the people behind it only for viewers who may see people (counts by department otherwise,
| with small groups suppressed).
*/

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $company = Company::factory()->create();
    $eng = Department::factory()->create(['name' => 'Engineering']);
    $senior = Designation::factory()->create(['name' => 'Senior Engineer']);
    $hire = fn (string $first, string $joined) => tap(app(HireEmployeeAction::class)->handle(
        ['first_name' => $first, 'last_name' => 'Pulse'], ['joining_date' => $joined], ['company_id' => $company->id, 'department_id' => $eng->id],
    ), fn (Employee $e) => forceLifecycle($e, LifecycleState::Active));
    $this->old = $hire('Olga', '2024-01-01');
    $this->new = $hire('Nina', '2026-10-05');
    app(PromoteEmployeeAction::class)->handle($this->old, ['designation_id' => $senior->id], '2026-10-10', null, 'test');
    $this->exec = tenantUser($this->tenant, ['analytics.view', 'analytics.executive']);
    $this->execWithPeople = tenantUser($this->tenant, ['analytics.view', 'analytics.executive', 'employee.view']);
    actAsTenant(null);
});

it('heads the pulse with a sentence from real figures', function () {
    actAsTenant($this->tenant);
    $pulse = app(WorkforcePulse::class)->for($this->exec);

    expect($pulse['movement']['now'])->toMatchArray(['promotions' => 1, 'joiners' => 1, 'moves' => 0, 'exits' => 0])
        ->and($pulse['headline'])->toContain('1 promotion and 1 joiner');

    $this->actingAs($this->exec)->get(WorkforceCommandCentre::getUrl())->assertOk()
        ->assertSee('Workforce pulse')->assertSee('Movement')->assertSee('Headcount')->assertSee('Critical skills')
        ->assertDontSee('pos-kpis', false);
});

it('drills down to names only for viewers who may see people', function () {
    actAsTenant($this->tenant);

    $names = app(WorkforcePulse::class)->drill($this->execWithPeople, 'promotions');
    expect($names['aggregated'])->toBeFalse()->and(collect($names['rows'])->pluck('label')->all())->toBe(['Olga Pulse']);

    // Aggregates group by department only: never by designation, which could single someone out.
    $promotions = app(WorkforcePulse::class)->drill($this->exec, 'promotions');
    expect(collect($promotions['rows'])->pluck('label')->all())->toBe(['Engineering'])
        ->and(collect($promotions['rows'])->pluck('label')->implode(' '))->not->toContain('Senior Engineer');

    $counts = app(WorkforcePulse::class)->drill($this->exec, 'joiners');
    expect($counts['aggregated'])->toBeTrue()
        ->and($counts['rows'][0])->toMatchArray(['person_id' => null, 'label' => 'Engineering', 'detail' => 'fewer than 5']);

    expect(app(WorkforcePulse::class)->drill($this->exec, 'salaries'))->toBeNull();
});

it('refuses the drill-down drawer to anyone who may not open the pulse', function () {
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['employee.view']));

    Livewire::test(DrawerHost::class)->call('show', 'pulse', 'promotions')->assertSee('Not available')->assertDontSee('Olga Pulse');
});
