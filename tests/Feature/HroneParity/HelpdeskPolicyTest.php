<?php

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\ServiceDesk\Services\ServiceCatalogue;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use App\Domain\ServiceDesk\Services\ServiceRequests;
use App\Filament\Pages\PolicyWizard;
use App\Filament\Pages\SetupCentre;
use App\Filament\Support\PolicySettingsSchema;
use App\Filament\Support\ServiceDeskActions;
use Livewire\Livewire;

require_once __DIR__.'/../ServiceDesk/ServiceDeskTestHelpers.php';

/* HROne setup parity HD-C — Helpdesk access policy: which categories and services an employee group may raise, and
 * whether its managers raise requests for their team. No policy keeps everything as before. */

beforeEach(function () {
    $this->travelTo('2026-09-21 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->manager = activeEmployee(null, ['servicedesk.request', 'servicedesk.team', 'performance.team', 'task.view']);
    $this->employee = activeEmployee($this->manager, ['servicedesk.request', 'task.view']);
    $this->payroll = TicketCategory::query()->where('code', 'PAYROLL')->firstOrFail();
    $this->other = TicketCategory::query()->where('code', 'OTHER')->firstOrFail();
    $this->payslip = sdApprovedService('PAYSLIP_COPY', ['availability' => ['employee' => true, 'manager' => true, 'hr' => true]], 'PAYROLL');
    $this->leave = sdApprovedService('LEAVE_HELP', ['availability' => ['employee' => true, 'manager' => true, 'hr' => true]], 'ATTENDANCE');
    $this->agent = sdAgent();
});

/** @param  array<string, mixed>  $settings */
function svcHelpdeskPolicy(array $settings, array $conditions = []): Policy
{
    $policy = Policy::create(['type' => 'helpdesk', 'name' => 'Helpdesk '.uniqid(), 'code' => 'HD_'.strtoupper(uniqid())]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'helpdesk', 'policy_id' => $policy->id, 'name' => 'Rule', 'conditions' => $conditions, 'priority' => $conditions === [] ? 1000 : 50]);

    return $policy;
}

function svcServiceCodes($versions): array
{
    return $versions->map(fn ($v) => $v->service->code)->sort()->values()->all();
}

it('offers every category and service when no helpdesk policy applies', function () {
    expect(svcServiceCodes(app(ServiceCatalogue::class)->availableTo($this->employee->user, $this->employee, 'employee')))->toBe(['LEAVE_HELP', 'PAYSLIP_COPY'])
        ->and(svcServiceCodes(app(ServiceCatalogue::class)->availableTo($this->manager->user, null, 'manager')))->toBe(['LEAVE_HELP', 'PAYSLIP_COPY']);
    $this->actingAs($this->employee->user);
    expect(ServiceDeskActions::askableCategories())->toHaveCount(TicketCategory::query()->where('status', 'active')->count());
    expect(app(ServiceDesk::class)->open($this->employee, $this->other, 'Q', 'D', 'normal', $this->employee->user)->status)->toBe('submitted');
});

it('limits an employee group to its categories and services, for themselves and their manager, never for HR', function () {
    svcHelpdeskPolicy(['categories' => [(string) $this->payroll->id], 'services' => []]);

    expect(svcServiceCodes(app(ServiceCatalogue::class)->availableTo($this->employee->user, $this->employee, 'employee')))->toBe(['PAYSLIP_COPY'])
        ->and(svcServiceCodes(app(ServiceCatalogue::class)->availableTo($this->agent, $this->employee, 'hr')))->toBe(['LEAVE_HELP', 'PAYSLIP_COPY']);

    $requests = app(ServiceRequests::class);
    expect(fn () => $requests->submit($this->leave, $this->employee, $this->employee->user))->toThrow(ServiceDeskRuleViolation::class, 'helpdesk policy');
    expect(fn () => $requests->submit($this->leave, $this->employee, $this->manager->user, [], ['source' => 'manager']))->toThrow(ServiceDeskRuleViolation::class, 'helpdesk policy');
    expect($requests->submit($this->payslip, $this->employee, $this->employee->user)->status)->not->toBe('draft')
        ->and($requests->submit($this->leave, $this->employee, $this->agent)->source)->toBe('hr');

    // "Ask HR": only the allowed categories are offered, and the others are refused.
    $this->actingAs($this->employee->user);
    expect(array_keys(ServiceDeskActions::askableCategories()))->toBe([$this->payroll->id]);
    expect(fn () => app(ServiceDesk::class)->open($this->employee, $this->other, 'Q', 'D', 'normal', $this->employee->user))->toThrow(ServiceDeskRuleViolation::class, 'helpdesk policy');
    expect(app(ServiceDesk::class)->open($this->employee, $this->payroll, 'Q', 'D', 'normal', $this->employee->user)->ticket_category_id)->toBe($this->payroll->id);
    expect(app(ServiceDesk::class)->open($this->employee, $this->other, 'Q', 'D', 'normal', $this->agent)->source)->toBe('hr');
});

it('lets a listed service through whatever its category, and narrows the policy to named employees', function () {
    svcHelpdeskPolicy(['categories' => [$this->payroll->id], 'services' => [$this->leave->id]], [['field' => 'employee_id', 'operator' => 'in', 'value' => [$this->employee->id]]]);

    expect(svcServiceCodes(app(ServiceCatalogue::class)->availableTo($this->employee->user, $this->employee, 'employee')))->toBe(['LEAVE_HELP'])
        // The manager is not named: no policy applies to them.
        ->and(svcServiceCodes(app(ServiceCatalogue::class)->availableTo($this->manager->user, $this->manager, 'employee')))->toBe(['LEAVE_HELP', 'PAYSLIP_COPY']);
    expect(app(ServiceRequests::class)->submit($this->leave, $this->employee, $this->employee->user)->service_definition_id)->toBe($this->leave->id);
});

it('stops managers of a policy that says no from raising requests for their team', function () {
    svcHelpdeskPolicy(['raise_on_behalf_of_team' => null]);
    expect(svcServiceCodes(app(ServiceCatalogue::class)->availableTo($this->manager->user, null, 'manager')))->toBe(['LEAVE_HELP', 'PAYSLIP_COPY']);

    svcHelpdeskPolicy(['raise_on_behalf_of_team' => false], [['field' => 'employee_id', 'operator' => 'in', 'value' => [$this->manager->id]]]);
    expect(app(ServiceCatalogue::class)->availableTo($this->manager->user, null, 'manager'))->toBeEmpty();
    expect(fn () => app(ServiceRequests::class)->submit($this->payslip, $this->employee, $this->manager->user, [], ['source' => 'manager']))
        ->toThrow(ServiceDeskRuleViolation::class, 'for your team');
    // The employee may still raise it for themselves.
    expect(app(ServiceRequests::class)->submit($this->payslip, $this->employee, $this->employee->user)->source)->toBe('web');
});

it('is a policy type with its own wizard steps and Setup centre entry', function () {
    $labels = array_column(PolicySettingsSchema::steps('helpdesk'), 'label');
    expect($labels)->toBe(['Categories and services', 'Raising on behalf'])
        ->and(PolicySettingsSchema::display(['type' => 'multiselect', 'options_from' => 'ticket_categories'], [(string) $this->payroll->id]))->toBe($this->payroll->name)
        ->and(PolicySettingsSchema::display(['type' => 'multiselect', 'options_from' => 'service_definitions'], [$this->leave->id]))->toBe($this->leave->name);

    $wizard = Livewire::withQueryParams(['type' => 'helpdesk'])->test(PolicyWizard::class)->assertOk();
    expect(array_column($wizard->instance()->steps(), 'label'))->toContain('Categories and services', 'Raising on behalf');

    Livewire::test(SetupCentre::class)->call('selectModule', 'helpdesk')->assertOk()->assertSee('Helpdesk access policy')
        ->assertSee('Resolver rules')->assertSee('Show the SLA status to the employee who raised the request');
});
