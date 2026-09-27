<?php

use App\Domain\Ai\Models\AiInteraction;
use App\Filament\Pages\AssistantPage;
use App\Filament\Pages\ConfigurationFinder;
use App\Filament\Pages\PayrollAuditor;
use App\Filament\Pages\WorkforceIntelligence;
use App\Filament\Resources\AiInteractions\AiInteractionResource;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->employee = activeEmployee(null, ['ai.use', 'task.view']);
    actAsTenant(null);
});

it('renders the AI pages with the right access', function () {
    $this->get(AssistantPage::getUrl())->assertOk()->assertSee('Employee Assistant')->assertSee('HR Copilot');
    $this->get(PayrollAuditor::getUrl())->assertOk()->assertSee('No calculated payroll run');
    $this->get(WorkforceIntelligence::getUrl())->assertOk()->assertSee('Attrition-risk signals');
    $this->get(ConfigurationFinder::getUrl(['term' => 'working hours']))->assertOk()->assertSee('Shifts');
    $this->get(AiInteractionResource::getUrl('index'))->assertOk();

    $this->actingAs($this->employee->user);
    $this->get(AssistantPage::getUrl())->assertOk()->assertSee('Employee Assistant')->assertDontSee('HR Copilot');
    $this->get(PayrollAuditor::getUrl())->assertForbidden();
    $this->get(WorkforceIntelligence::getUrl())->assertForbidden();
    $this->get(AiInteractionResource::getUrl('index'))->assertForbidden();
});

it('asks and rates through the chat page', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->employee->user);
    Livewire::test(AssistantPage::class)->set('question', 'What should I do next?')->call('ask')->assertSee('needs your attention');
    $interaction = AiInteraction::query()->where('user_id', $this->employee->user_id)->first();
    expect($interaction->intent)->toBe('attention');
    Livewire::test(AssistantPage::class)->call('rate', $interaction->id, 'down')->assertNotified('Thanks for the feedback');
    expect($interaction->refresh()->feedback)->toBe('down');
    Livewire::test(AssistantPage::class)->call('switchTo', 'hr')->assertSet('assistant', 'employee');
});
