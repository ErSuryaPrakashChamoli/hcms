<?php

use App\Domain\Analytics\Datasets\ServiceDeskDataset;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use App\Filament\Resources\TicketCategories\Pages\ManageTicketCategories;
use App\Filament\Resources\TicketCategories\TicketCategoryResource;
use App\Filament\Resources\Tickets\TicketResource;
use Livewire\Livewire;

require_once __DIR__.'/../ServiceDesk/ServiceDeskTestHelpers.php';

/* HROne setup parity HD-A — Helpdesk settings and ticket categories: purpose, mandatory RCA, feedback per category,
 * mandatory feedback and the SLA shown to the requester. Every switch defaults to the earlier behaviour. */

beforeEach(function () {
    $this->travelTo('2026-09-21 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->employee = activeEmployee(null, ['servicedesk.request', 'task.view']);
    $this->agent = sdAgent();
    $this->desk = app(ServiceDesk::class);
    $this->category = TicketCategory::query()->where('code', 'PAYROLL')->firstOrFail();
});

/** A resolved "Ask HR" request of the category, raised by the employee and worked by the agent. */
function svcResolvedTicket(TicketCategory $category, $employee, $agent, array $rca = []): Ticket
{
    $ticket = app(ServiceDesk::class)->open($employee, $category, 'Payslip question', 'Why is my tax higher?', 'normal', $employee->user);
    app(ServiceDesk::class)->assign($ticket, $agent, $agent);

    return app(ServiceDesk::class)->resolve($ticket->refresh(), 'Explained the regime change', $agent, null, null, $rca['root_cause'] ?? null, $rca['code'] ?? null);
}

it('keeps every category and the closing flow as before by default', function () {
    expect($this->category->purpose)->toBe('general')->and($this->category->rca_required)->toBeFalse()->and($this->category->feedback_enabled)->toBeTrue()
        ->and(app(SettingsRepository::class)->get('servicedesk.show_sla_to_employee'))->toBeFalse()
        ->and(app(SettingsRepository::class)->get('servicedesk.feedback_required'))->toBeFalse();

    $ticket = svcResolvedTicket($this->category, $this->employee, $this->agent);
    expect($ticket->status)->toBe('resolved')->and($ticket->root_cause)->toBeNull();

    // Closing without a rating still works; a rating given is kept.
    $closed = $this->desk->close($ticket, $this->employee->user);
    expect($closed->status)->toBe('closed')->and($closed->satisfaction)->toBeNull();
    $rated = $this->desk->close(svcResolvedTicket($this->category, $this->employee, $this->agent), $this->employee->user, 4, 'Quick');
    expect($rated->satisfaction)->toBe(4);

    // The requester does not see the SLA section.
    $this->actingAs($this->employee->user);
    $this->get(TicketResource::getUrl('view', ['record' => $rated]))->assertOk()->assertDontSee('Service level');
});

it('needs the root cause to resolve a request in a category with mandatory RCA, and reports the coded cause', function () {
    $this->category->update(['rca_required' => true]);
    $ticket = $this->desk->open($this->employee, $this->category->refresh(), 'Payslip question', 'Why?', 'normal', $this->employee->user);
    $this->desk->assign($ticket, $this->agent, $this->agent);

    expect(fn () => $this->desk->resolve($ticket->refresh(), 'Done', $this->agent))->toThrow(ServiceDeskRuleViolation::class, 'root cause (RCA)')
        ->and($ticket->refresh()->status)->toBe('assigned');
    expect(fn () => $this->desk->resolve($ticket->refresh(), 'Done', $this->agent, null, null, 'Wrong regime', 'astrology'))->toThrow(ServiceDeskRuleViolation::class, 'Unknown root cause');

    $resolved = $this->desk->resolve($ticket->refresh(), 'Done', $this->agent, null, null, 'The new regime was applied by default', 'data');
    expect($resolved->status)->toBe('resolved')->and($resolved->root_cause)->toBe('The new regime was applied by default')->and($resolved->root_cause_code)->toBe('data');

    $row = collect((new ServiceDeskDataset)->fields())->map(fn ($f) => ($f['value'])($resolved->refresh()->load(['category', 'service', 'employee', 'assignee'])));
    expect($row['root_cause'])->toBe('Data error');

    // The agent sees the analysis on the case; the requester does not.
    $this->actingAs($this->agent);
    $this->get(TicketResource::getUrl('view', ['record' => $resolved]))->assertOk()->assertSee('Root cause analysis')->assertSee('The new regime was applied by default');
    $this->actingAs($this->employee->user);
    $this->get(TicketResource::getUrl('view', ['record' => $resolved]))->assertOk()->assertDontSee('The new regime was applied by default');
});

it('makes the requester rate the help when feedback is mandatory, except where the category takes no feedback or the system closes', function () {
    app(SettingsRepository::class)->set('servicedesk.feedback_required', true, 'HROne parity');

    $ticket = svcResolvedTicket($this->category, $this->employee, $this->agent);
    expect(fn () => $this->desk->close($ticket, $this->employee->user))->toThrow(ServiceDeskRuleViolation::class, 'Rate the help');
    expect($this->desk->close($ticket->refresh(), $this->employee->user, 5)->satisfaction)->toBe(5);

    // HR closing and the automatic close are exempt.
    expect($this->desk->close(svcResolvedTicket($this->category, $this->employee, $this->agent), $this->agent)->status)->toBe('closed')
        ->and($this->desk->close(svcResolvedTicket($this->category, $this->employee, $this->agent))->status)->toBe('closed');

    // A category that takes no feedback: no rating is asked for or kept.
    $this->category->update(['feedback_enabled' => false]);
    $quiet = svcResolvedTicket($this->category->refresh(), $this->employee, $this->agent);
    expect($this->desk->feedbackRequired($quiet))->toBeFalse();
    $closed = $this->desk->close($quiet, $this->employee->user, 2, 'ignored');
    expect($closed->status)->toBe('closed')->and($closed->satisfaction)->toBeNull()->and($closed->satisfaction_comment)->toBeNull();
});

it('shows the requester the SLA when the organisation turns it on, without who works the case', function () {
    $ticket = $this->desk->open($this->employee, $this->category, 'Payslip question', 'Why?', 'normal', $this->employee->user);
    $this->desk->assign($ticket, $this->agent, $this->agent);
    app(SettingsRepository::class)->set('servicedesk.show_sla_to_employee', true, 'HROne parity');

    $this->actingAs($this->employee->user);
    $this->get(TicketResource::getUrl('view', ['record' => $ticket]))->assertOk()->assertSee('Service level')->assertSee('On track')
        ->assertDontSee('Assignment and SLA')->assertDontSee($this->agent->name);
    expect(TicketResource::requesterSlaText($ticket->refresh()))->toBe('On track');

    $this->travel(10)->days();
    expect(TicketResource::requesterSlaText($ticket->refresh()))->toBe('Overdue');
});

it('configures purpose, RCA and feedback on the category screen with filters, for service desk managers only', function () {
    expect(fn () => $this->category->update(['purpose' => 'teleport']))->toThrow(RuntimeException::class, 'Unknown category purpose');

    Livewire::test(ManageTicketCategories::class)->assertOk()
        ->filterTable('id', [$this->category->id])->assertCanSeeTableRecords([$this->category])
        ->callTableAction('edit', $this->category, ['purpose' => 'travel_booking', 'rca_required' => true, 'feedback_enabled' => false])
        ->assertHasNoTableActionErrors();
    expect($this->category->refresh()->purpose)->toBe('travel_booking')->and($this->category->rca_required)->toBeTrue()->and($this->category->feedback_enabled)->toBeFalse();

    $changed = app(SetupCatalogue::class)->save('helpdesk', [SetupCatalogue::field('servicedesk.feedback_required') => true], 'HROne parity', $this->admin);
    expect($changed)->toBe(['servicedesk.feedback_required']);

    $this->actingAs($this->employee->user);
    $this->get(TicketCategoryResource::getUrl('index'))->assertForbidden();
});
