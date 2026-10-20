<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Employment\Events\EmploymentEvent;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use App\Domain\People\Models\PersonAddress;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Domain\ServiceDesk\Services\DomainActionExecutor;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use App\Domain\ServiceDesk\Services\ServiceRequests;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/ServiceDeskTestHelpers.php';
require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->manager = activeEmployee(null, ['servicedesk.request', 'servicedesk.team', 'performance.team', 'task.view', 'task.act', 'leave.approve']);
    $this->employee = activeEmployee($this->manager, ['servicedesk.request', 'task.view', 'leave.apply', 'attendance.regularise']);
    $this->approver = tenantUser($this->tenant, ['task.view', 'task.act']);
    $this->executor = sdAgent(['employee.sensitive.update', 'employee.sensitive.view', 'employee.update', 'employee.create', 'employee.position']);
    $this->requests = app(ServiceRequests::class);
    $this->bankData = ['account_holder_name' => 'Asha Rao', 'bank_name' => 'HDFC', 'ifsc' => 'HDFC0001234', 'account_number' => '50100123456789', 'account_type' => 'salary', 'is_primary' => true];
});

function approveTicket(Ticket $ticket, $approver): void
{
    $task = WorkflowTask::query()->where('workflow_instance_id', $ticket->refresh()->workflow_instance_id)->where('status', 'pending')->firstOrFail();
    app(WorkflowEngine::class)->completeTask($task, 'approved', 'Looks right', $approver);
}

it('changes a bank account: request → approval → People domain action, once, with linked audit and value-free events', function () {
    sdApprovalWorkflow($this->approver, 'bank_approval');
    $service = sdApprovedService('BANK_CHANGE', ['domain_action' => 'profile.bank_account', 'approval_required' => true, 'workflow_key' => 'bank_approval', 'confidentiality' => 'sensitive'], 'PROFILE');
    $events = [];
    Event::listen(EmploymentEvent::class, function (EmploymentEvent $e) use (&$events) {
        $events[] = $e;
    });

    $ticket = $this->requests->submit($service, $this->employee, $this->employee->user, $this->bankData + ['smuggled' => 'x']);
    expect($ticket->status)->toBe('awaiting_approval')->and($ticket->domain_action_status)->toBe('awaiting_approval')
        ->and($ticket->sla_paused_at)->not->toBeNull()
        ->and(EmployeeBankAccount::query()->count())->toBe(0)
        ->and((string) DB::table('tickets')->where('id', $ticket->id)->value('form_data'))->not->toContain('50100123456789')
        ->and($ticket->form_data)->not->toHaveKey('smuggled');

    // Not ready yet: nobody may execute before approval.
    expect(fn () => app(DomainActionExecutor::class)->execute($ticket, $this->executor))->toThrow(ServiceDeskRuleViolation::class, 'not ready');

    approveTicket($ticket, $this->approver);
    $ticket->refresh();
    expect($ticket->status)->toBe('in_progress')->and($ticket->domain_action_status)->toBe('ready')->and($ticket->approved_by)->toBe($this->approver->id);

    // Separation of duties: the requester and the approver never execute.
    $approverAgent = $this->approver;
    $approverAgent->roles()->attach(sdTeam()->id);
    expect(fn () => app(DomainActionExecutor::class)->execute($ticket, $this->employee->user))->toThrow(ServiceDeskRuleViolation::class)
        ->and(fn () => app(DomainActionExecutor::class)->execute($ticket, sdAgent()))->toThrow(ServiceDeskRuleViolation::class, 'employee.sensitive.update');

    $done = app(DomainActionExecutor::class)->execute($ticket, $this->executor);
    $account = EmployeeBankAccount::query()->where('employee_id', $this->employee->id)->firstOrFail();
    expect($done->status)->toBe('resolved')->and($done->domain_action_status)->toBe('executed')
        ->and($done->domain_reference_type)->toBe($account->getMorphClass())->and((int) $done->domain_reference_id)->toBe($account->id)
        ->and($done->domain_action_executed_by)->toBe($this->executor->id)
        ->and($done->form_data)->toBe([])->and($done->form_data_purged_at)->not->toBeNull()
        ->and((string) $account->account_number)->toBe('50100123456789');

    // Which request caused the change, and which action changed the record.
    $audit = AuditEvent::query()->where('entity_type', EmployeeBankAccount::class)->where('entity_id', (string) $account->id)->firstOrFail();
    expect($audit->approval_reference)->toBe($ticket->number)->and($audit->operation_id)->toBe($ticket->operation_id)
        ->and(json_encode($audit->fieldChanges()->get()->toArray()))->not->toContain('50100123456789');
    $event = collect($events)->firstWhere('name', 'employee.bank_account_changed');
    expect($event->context)->toMatchArray(['operation' => 'added', 'source' => 'service_desk', 'reference' => $ticket->number, 'operation_id' => $ticket->operation_id])
        ->and(json_encode($event->context))->not->toContain('50100123456789');

    // A retry never changes the data again.
    app(DomainActionExecutor::class)->execute($ticket->refresh(), $this->executor);
    expect(EmployeeBankAccount::query()->where('employee_id', $this->employee->id)->count())->toBe(1);
});

it('refuses an approval given by the requester, and resolves a rejected request without changing anything', function () {
    sdApprovalWorkflow($this->employee->user, 'self_approval');
    $service = sdApprovedService('PAN_UPDATE_X', ['domain_action' => 'profile.statutory_identity', 'approval_required' => true, 'workflow_key' => 'self_approval', 'confidentiality' => 'sensitive'], 'PROFILE');
    $ticket = $this->requests->submit($service, $this->employee, $this->employee->user, ['pan' => 'ABCDE1234F']);
    approveTicket($ticket, $this->employee->user);
    expect($ticket->refresh()->domain_action_status)->toBe('refused')->and($ticket->status)->toBe('in_progress')
        ->and(fn () => app(DomainActionExecutor::class)->execute($ticket, $this->executor))->toThrow(ServiceDeskRuleViolation::class, 'not ready')
        ->and(AuditEvent::query()->where('metadata->event', 'REQUEST_APPROVAL_SOD_REFUSED')->exists())->toBeTrue();

    sdApprovalWorkflow($this->approver, 'pan_approval');
    $service2 = sdApprovedService('PAN_UPDATE_Y', ['domain_action' => 'profile.statutory_identity', 'approval_required' => true, 'workflow_key' => 'pan_approval', 'confidentiality' => 'sensitive'], 'PROFILE');
    $rejected = $this->requests->submit($service2, $this->employee, $this->employee->user, ['pan' => 'ABCDE1234F']);
    $task = WorkflowTask::query()->where('workflow_instance_id', $rejected->workflow_instance_id)->firstOrFail();
    app(WorkflowEngine::class)->completeTask($task, 'rejected', 'Mismatch', $this->approver);
    expect($rejected->refresh()->status)->toBe('resolved')->and($rejected->domain_action_status)->toBe('rejected')
        ->and($rejected->form_data)->toBe([])->and($this->employee->statutoryDetail()->first())->toBeNull();
});

it('lets an employee request a change to their own record only, and HR change an address without approval when the service needs none', function () {
    $service = sdApprovedService('ADDRESS_X', ['domain_action' => 'profile.address', 'confidentiality' => 'sensitive'], 'PROFILE');
    $stranger = activeEmployee(null, ['servicedesk.request']);
    $address = ['type' => array_key_first(config('peopleos.people.address_types')), 'address_line_1' => '12 MG Road', 'city' => 'Pune', 'country_code' => 'IN'];
    expect(fn () => $this->requests->submit($service, $stranger, $this->employee->user, $address, ['source' => 'web']))->toThrow(ServiceDeskRuleViolation::class, 'own record');

    $ticket = $this->requests->submit($service, $this->employee, $this->employee->user, $address);
    expect($ticket->domain_action_status)->toBe('ready')->and($ticket->status)->toBe('submitted');

    // The employee's manager never sees a sensitive profile request; the requester sees their values
    // masked; an executor holding the People permission sees them.
    expect(app(CaseAccess::class)->canView($this->manager->user, $ticket))->toBeFalse()
        ->and(app(CaseAccess::class)->formDataFor($this->employee->user, $ticket)['address_line_1'])->toMatchArray(['value' => '••••Road', 'masked' => true])
        ->and(app(CaseAccess::class)->formDataFor($this->executor, $ticket)['address_line_1'])->toMatchArray(['value' => '12 MG Road', 'masked' => false])
        ->and(app(CaseAccess::class)->formDataFor(sdAgent(), $ticket)['address_line_1']['masked'])->toBeTrue();

    app(DomainActionExecutor::class)->execute($ticket, $this->executor);
    expect(PersonAddress::query()->where('person_id', $this->employee->person_id)->value('address_line_1'))->toBe('12 MG Road')
        ->and($ticket->refresh()->status)->toBe('resolved');
});

it('hands leave and letters to their domains at submission, and closes the case on the domain decision', function () {
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    app(LeaveAccrual::class)->accrue($this->employee);
    $leaveService = sdApprovedService('LEAVE_APPLY', ['domain_action' => 'leave.request'], 'ATTENDANCE');
    $el = LeaveType::query()->where('code', 'EL')->firstOrFail();

    $ticket = $this->requests->submit($leaveService, $this->employee, $this->employee->user, ['leave_type_id' => $el->id, 'from_date' => '2026-09-28', 'to_date' => '2026-09-28', 'leave_reason' => 'Family event'], ['idempotency_key' => 'leave-1']);
    $leave = LeaveRequest::query()->where('employee_id', $this->employee->id)->firstOrFail();
    expect($ticket->status)->toBe('awaiting_approval')->and($ticket->domain_reference_type)->toBe($leave->getMorphClass())->and((int) $ticket->domain_reference_id)->toBe($leave->id)
        ->and($leave->idempotency_key)->toBe('sd-'.$ticket->correlation_id);
    expect($this->requests->submit($leaveService, $this->employee, $this->employee->user, ['leave_type_id' => $el->id, 'from_date' => '2026-09-28', 'to_date' => '2026-09-28', 'leave_reason' => 'Family event'], ['idempotency_key' => 'leave-1'])->id)->toBe($ticket->id)
        ->and(LeaveRequest::query()->count())->toBe(1);

    $this->actingAs($this->manager->user);
    app(Leaves::class)->approve($leave, 'Enjoy', $this->manager->user);
    expect($ticket->refresh()->status)->toBe('resolved')->and($ticket->resolution)->toBe('Leave approved.');

    $code = LetterTemplate::query()->where('type', 'experience')->value('code');
    $letterService = sdApprovedService('EXP_LETTER', ['domain_action' => 'letter.request'], 'LETTER');
    $letterTicket = $this->requests->submit($letterService, $this->employee, $this->employee->user, ['template' => $code, 'purpose' => 'Visa']);
    $letter = Letter::query()->findOrFail($letterTicket->domain_reference_id);
    expect($letter->requested_by)->toBe($this->employee->user->id)->and($letter->source_id)->toBe($letterTicket->id);
    $hr = tenantUser($this->tenant, ['letter.issue']);
    if ($letter->status === 'pending_approval') {
        app(Letters::class)->approve($letter, $hr);
    }
    app(Letters::class)->issue($letter->refresh(), $hr);
    expect($letterTicket->refresh()->status)->toBe('resolved');
});

it('links a compensation proposal made in Compensation, never mutating salary from the desk', function () {
    $service = sdApprovedService('SALARY_CHANGE', ['domain_action' => 'compensation.proposal', 'confidentiality' => 'sensitive', 'availability' => ['employee' => false, 'manager' => true, 'hr' => true]], 'PAYROLL');
    $ticket = $this->requests->submit($service, $this->employee, $this->manager->user, [], ['source' => 'manager', 'description' => 'Retention case']);
    expect($ticket->domain_action_status)->toBe('ready');

    ['proposer' => $proposer] = compensationActors();
    $proposer->roles()->attach(sdTeam()->id);
    compensate($this->employee, 600000, '2025-01-01');
    $structure = SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail();
    $change = app(CompensationChanges::class)->propose($this->employee, ['change_type' => 'revision', 'effective_from' => '2026-11-01', 'ctc_annual' => 660000, 'salary_structure_id' => $structure->id, 'component_values' => ['CONV' => 1600], 'reason' => 'Retention'], $proposer);

    expect(fn () => app(DomainActionExecutor::class)->execute($ticket, $proposer, ['compensation_reference' => 'CMP-NOPE']))->toThrow(ServiceDeskRuleViolation::class, 'compensation proposal you created');
    app(DomainActionExecutor::class)->execute($ticket->refresh(), $proposer, ['compensation_reference' => $change->reference]);
    expect($ticket->refresh()->status)->toBe('resolved')->and((int) $ticket->domain_reference_id)->toBe($change->id)
        ->and($change->refresh()->status)->toBe('draft'); // the Compensation chain continues there
});

it('changes a manager through Employment after approval by HR', function () {
    sdApprovalWorkflow($this->approver, 'manager_approval');
    $newManager = activeEmployee(null, ['task.view']);
    $service = sdApprovedService('MANAGER_CHANGE_X', ['domain_action' => 'employment.manager_change', 'approval_required' => true, 'workflow_key' => 'manager_approval', 'availability' => ['employee' => false, 'manager' => true, 'hr' => true]], 'PROFILE');
    $ticket = $this->requests->submit($service, $this->employee, $this->manager->user, ['manager_employee_code' => $newManager->employee_code, 'effective_from' => '2026-10-01', 'relationship_type' => 'line'], ['source' => 'manager']);
    approveTicket($ticket, $this->approver);
    app(DomainActionExecutor::class)->execute($ticket->refresh(), $this->executor);

    expect(ReportingRelationship::query()->where('employee_id', $this->employee->id)->where('manager_id', $newManager->id)->exists())->toBeTrue()
        ->and($ticket->refresh()->status)->toBe('resolved');
});

it('withdraws a pending change when the request is cancelled or resolved without applying it', function () {
    $service = sdApprovedService('EMERGENCY_X', ['domain_action' => 'profile.emergency_contact', 'confidentiality' => 'sensitive'], 'PROFILE');
    $ticket = $this->requests->submit($service, $this->employee, $this->employee->user, ['name' => 'Ravi', 'phone' => '9999999999']);
    app(ServiceDesk::class)->cancel($ticket, $this->employee->user, 'Not needed');
    expect($ticket->refresh()->status)->toBe('cancelled')->and($ticket->domain_action_status)->toBe('cancelled')->and($ticket->form_data)->toBe([])
        ->and(fn () => app(DomainActionExecutor::class)->execute($ticket, $this->executor))->toThrow(ServiceDeskRuleViolation::class);
});
