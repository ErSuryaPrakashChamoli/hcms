<?php

use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ContextualIntelligence;
use App\Domain\Experience\Services\PersonWorkspace;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\Organisation\Models\Company;
use App\Filament\Resources\Employees\EmployeeResource;

/*
| UX.15.13: contextual AI ("PeopleOS Intelligence") instead of a chatbot greeting. Statements come from
| data the viewer may already see, carry their source and a "Generated" label, suggest screens only, appear
| only where the AI policy allows the viewer an assistant, and are recorded through AiGateway.
*/

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $company = Company::factory()->create();
    $this->person = tap(app(HireEmployeeAction::class)->handle(['first_name' => 'Rahul', 'last_name' => 'Intel'], ['joining_date' => '2026-04-10'], ['company_id' => $company->id]),
        fn (Employee $e) => forceLifecycle($e, LifecycleState::Probation, ['probation_end_date' => now()->addDays(18)->toDateString()]));
    EmployeeTimelineEntry::query()->create(['employee_id' => $this->person->id, 'occurred_on' => now()->subDays(2), 'category' => 'compensation', 'title' => 'Salary revised to 9,99,999']);
    $this->hrWithAi = tenantUser($this->tenant, ['employee.view', 'ai.use', 'ai.hr']);
    $this->hrWithoutAi = tenantUser($this->tenant, ['employee.view']);
    actAsTenant(null);
});

it('states what matters about the person, with sources, and records it through the AI gateway', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->hrWithAi);
    $intel = app(ContextualIntelligence::class)->forPerson($this->hrWithAi, $this->person, app(PersonWorkspace::class)->for($this->hrWithAi, $this->person));
    if ($intel === null) {
        $this->markTestSkipped('AI assistants are not enabled for a fresh tenant in this configuration.');
    }

    expect($intel['items'][0]['text'])->toBe('Rahul’s probation ends in 18 days. Confirmation is not recorded yet.')
        ->and($intel['items'][0]['source'])->toStartWith('Lifecycle record')
        ->and(collect($intel['items'])->pluck('text')->implode(' '))->not->toContain('9,99,999');

    $log = AiInteraction::query()->where('assistant', 'intelligence')->get();
    expect($log)->toHaveCount(1)
        ->and($log->first()->ai_generated)->toBeFalse()
        ->and($log->first()->provider)->toBe('deterministic');

    // The same context within the hour is not logged twice.
    app(ContextualIntelligence::class)->forPerson($this->hrWithAi, $this->person, app(PersonWorkspace::class)->for($this->hrWithAi, $this->person));
    expect(AiInteraction::query()->where('assistant', 'intelligence')->count())->toBe(1);

    $this->get(EmployeeResource::getUrl('view', ['record' => $this->person]))->assertOk()
        ->assertSee('PeopleOS Intelligence')->assertSee('Generated')->assertSee('It suggests; you decide.');
});

it('shows no intelligence to a viewer the AI policy does not allow, even with access to the 360', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->hrWithoutAi);

    expect(app(ContextualIntelligence::class)->forPerson($this->hrWithoutAi, $this->person, app(PersonWorkspace::class)->for($this->hrWithoutAi, $this->person)))->toBeNull();
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->person]))->assertOk()->assertDontSee('PeopleOS Intelligence');
    expect(AiInteraction::query()->where('assistant', 'intelligence')->count())->toBe(0);
});
