<?php

use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Compensation\Services\CompensationChanges;
use App\Domain\Compliance\Services\InvestmentDeclarations;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Exit\Services\Exits;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Loans\Models\Loan;
use App\Domain\Loans\Services\Loans;
use App\Domain\Payroll\Events\PayrollEvent;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Exceptions\WorkflowException;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowAction;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Services\EscalationEngine;
use App\Domain\Workflow\Services\WorkflowDefinition;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| HROne setup parity P5 (Workflow): request types that run through configurable approval workflows (regularisation,
| loan, claim, variable pay, compensation change, letter, resignation, tax declaration), the payroll request events,
| the document node writing letters, functional manager / HR business partner approvers, deciding automatically at the
| last level, and "only the first matching workflow". Every default keeps the earlier behaviour.
*/

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->engine = app(WorkflowEngine::class);
    $this->approver = tenantUser($this->tenant, ['task.view', 'task.act', 'payroll.manage', 'attendance.approve', 'letter.issue', 'exit.manage']);
});

/** A published one-step approval workflow (decided by one user) on a trigger. */
function wfcApprovalFlow(string $trigger, ?string $runsOn, int $approverId, array $attributes = []): Workflow
{
    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Approve', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $approverId]]]]);

    return publishWorkflow($nodes, $edges, ['trigger_event' => $trigger, 'subject_type' => $runsOn] + $attributes);
}

/** Decide the pending task of the subject's run as the given user. */
function wfcDecide(object $subject, string $decision, $user): WorkflowInstance
{
    $instance = WorkflowInstance::query()->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->latest('id')->firstOrFail();
    $task = $instance->tasks()->where('status', TaskStatus::Pending)->firstOrFail();

    return app(WorkflowEngine::class)->completeTask($task, $decision, $decision === 'rejected' ? 'Not now' : 'Fine', $user);
}

it('keeps today\'s behaviour by default: a workflow on the regularisation trigger that runs on any record decides nothing', function () {
    expect(config('peopleos.settings')['workflow.first_match_only'])->toBeFalse();
    $employee = employeeWithUser(null, ['attendance.regularise', 'task.view', 'task.act']);
    forceLifecycle($employee, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    assignSchedule($employee, weeklySchedule(generalShift()));
    wfcApprovalFlow('attendance.regularisation_requested', null, $this->approver->id);

    $reg = app(Regularisations::class)->request($employee, '2026-09-18', 'missed_punch', 'Forgot', '2026-09-18 09:30', '2026-09-18 18:30', $employee->user);
    wfcDecide($reg, 'approved', $this->approver);

    expect($reg->fresh()->status)->toBe('pending')
        ->and(app(ApprovalCenter::class)->pending($this->approver)->pluck('id'))->toContain('regularisation:'.$reg->id);
});

it('decides a regularisation by a workflow set to run on regularisations, and hides it from the Approval Center meanwhile', function () {
    $employee = employeeWithUser(null, ['attendance.regularise', 'task.view', 'task.act']);
    forceLifecycle($employee, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    assignSchedule($employee, weeklySchedule(generalShift()));
    wfcApprovalFlow('attendance.regularisation_requested', AttendanceRegularisation::class, $this->approver->id);

    $reg = app(Regularisations::class)->request($employee, '2026-09-18', 'missed_punch', 'Forgot', '2026-09-18 09:30', '2026-09-18 18:30', $employee->user);
    $queue = app(ApprovalCenter::class)->pending($this->approver)->pluck('id');
    expect($queue)->not->toContain('regularisation:'.$reg->id)->and($queue->filter(fn ($id) => str_starts_with($id, 'workflow_task:')))->toHaveCount(1);

    wfcDecide($reg, 'approved', $this->approver);
    expect($reg->fresh()->status)->toBe('approved')->and($reg->fresh()->reviewed_by)->toBe($this->approver->id);
});

it('raises payroll request events and lets a workflow on "loan requested" approve or reject the loan', function () {
    Event::fake([PayrollEvent::class]);
    $employee = employeeWithUser(null, ['payroll.request', 'task.view']);
    $loan = app(Loans::class)->request($employee, 'personal', 12000, 6, 'Medical', $employee->user);
    Event::assertDispatched(PayrollEvent::class, fn (PayrollEvent $e) => $e->name === 'payroll.loan_requested' && $e->subject->is($loan) && $e->context['employee_id'] === $employee->id);
    app(Loans::class)->reject($loan, 'Budget', $this->approver);
    Event::assertDispatched(PayrollEvent::class, fn (PayrollEvent $e) => $e->name === 'payroll.loan_rejected');
});

it('approves and rejects loans through their workflow in the name of the last approver, out of the Approval Center meanwhile', function () {
    $employee = employeeWithUser(null, ['payroll.request', 'task.view']);
    wfcApprovalFlow('payroll.loan_requested', null, $this->approver->id);

    $loan = app(Loans::class)->request($employee, 'personal', 12000, 6, 'Medical', $employee->user);
    expect(app(ApprovalCenter::class)->pending($this->approver)->pluck('id'))->not->toContain('loan:'.$loan->id);
    wfcDecide($loan, 'approved', $this->approver);
    expect($loan->fresh()->status)->toBe('approved')->and($loan->fresh()->reviewed_by)->toBe($this->approver->id);

    $second = app(Loans::class)->request(employeeWithUser(null, ['payroll.request']), 'personal', 5000, 2, 'Rent', null);
    wfcDecide($second, 'rejected', $this->approver);
    expect($second->fresh()->status)->toBe('rejected');
});

it('moves a compensation change one step per workflow: review, then approval, keeping the separation of duties', function () {
    syncComplianceRules();
    payrollCompany();
    $actors = compensationActors();
    $employee = salariedEmployee(600000, ['task.view'], '2026-04-01');
    $structure = SalaryStructure::query()->where('code', 'STANDARD')->firstOrFail();
    wfcApprovalFlow('compensation.change.submitted', null, $actors['reviewer']->id);
    wfcApprovalFlow('compensation.change.reviewed', null, $actors['approver']->id);
    $changes = app(CompensationChanges::class);

    $change = $changes->propose($employee, ['change_type' => 'annual_increment', 'effective_from' => '2026-10-01', 'salary_structure_id' => $structure->id, 'ctc_annual' => 660000, 'reason' => 'Increment'], $actors['proposer']);
    $changes->submit($change, $actors['proposer']);
    expect(app(ApprovalCenter::class)->pending($actors['reviewer'])->pluck('id'))->not->toContain('compensation:'.$change->id);

    wfcDecide($change->fresh(), 'approved', $actors['reviewer']);
    expect($change->fresh()->status)->toBe('under_review')->and($change->fresh()->reviewed_by)->toBe($actors['reviewer']->id);

    wfcDecide($change->fresh(), 'approved', $actors['approver']);
    expect($change->fresh()->status)->toBe('approved')->and($change->fresh()->approved_by)->toBe($actors['approver']->id);
});

it('lets a workflow set to run on letters approve them; the person who asked for a letter never approves it', function () {
    $employee = employeeWithUser();
    $template = LetterTemplate::create(['name' => 'NOC', 'code' => 'NOC_WF', 'type' => 'noc', 'subject' => 'NOC', 'requires_approval' => true, 'body' => '<p>Dear {{ employee.first_name }}</p>']);
    wfcApprovalFlow('letter.requested', Letter::class, $this->approver->id);

    $letter = app(Letters::class)->generate($template, $employee, [], $this->hr);
    expect($letter->status)->toBe('pending_approval')
        ->and(app(ApprovalCenter::class)->pending($this->approver)->pluck('id'))->not->toContain('letter:'.$letter->id);
    wfcDecide($letter, 'approved', $this->approver);
    expect($letter->fresh()->status)->toBe('approved')->and($letter->fresh()->approved_by)->toBe($this->approver->id);

    // The requester as the last approver: the letter's own rule refuses, and it stays for the Approval Center.
    $other = app(Letters::class)->generate($template, $employee, [], $this->approver);
    wfcDecide($other, 'approved', $this->approver);
    expect($other->fresh()->status)->toBe('pending_approval');
});

it('accepts a resignation through its workflow, or withdraws it when not accepted', function () {
    $manager = employeeWithUser();
    $employee = employeeWithUser($manager, ['exit.resign', 'task.view']);
    forceLifecycle($employee, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    wfcApprovalFlow('exit.resignation_submitted', null, $manager->user_id);

    $case = app(Exits::class)->resign($employee, 'Moving city', null, $employee->user);
    wfcDecide($case, 'approved', $manager->user);
    expect($case->fresh()->resignation_accepted_by)->toBe($manager->user_id)->and($case->fresh()->resignation_accepted_at)->not->toBeNull()
        ->and($case->fresh()->status)->toBe('notice');

    $other = employeeWithUser($manager, ['exit.resign']);
    forceLifecycle($other, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    $second = app(Exits::class)->resign($other, 'Higher studies', null, $other->user);
    wfcDecide($second, 'rejected', $manager->user);
    expect($second->fresh()->status)->toBe('withdrawn')->and($other->fresh()->lifecycle_state)->toBe(LifecycleState::Active);
});

it('verifies a submitted investment declaration through its workflow, or returns it to the employee', function () {
    syncComplianceRules();
    payrollCompany();
    $employee = salariedEmployee(1200000, ['payroll.payslip', 'payroll.request'], from: '2026-01-01');
    wfcApprovalFlow('payroll.tax_declaration_submitted', null, $this->approver->id);

    $declaration = app(InvestmentDeclarations::class)->save($employee->user, $employee, '2026-27', ['regime' => 'old', 'items' => []], true);
    wfcDecide($declaration, 'approved', $this->approver);
    expect($declaration->fresh()->status)->toBe('verified')->and($declaration->fresh()->verified_by)->toBe($this->approver->id);

    // Saving again while verified is refused as before; a fresh declaration of another employee is sent back.
    $other = salariedEmployee(900000, ['payroll.payslip'], from: '2026-01-01');
    $second = app(InvestmentDeclarations::class)->save($other->user, $other, '2026-27', ['regime' => 'old', 'items' => []], true);
    wfcDecide($second, 'rejected', $this->approver);
    expect($second->fresh()->status)->toBe('draft');
});

it('writes a letter from the document node, issues it when asked, and skips a node without a template as before', function () {
    Storage::fake(config('peopleos.documents.disk'));
    $employee = employeeWithUser();
    $template = LetterTemplate::create(['name' => 'Welcome', 'code' => 'WELCOME_WF', 'type' => 'custom', 'subject' => 'Welcome {{ employee.first_name }}', 'requires_approval' => false, 'body' => '<p>Welcome</p>']);

    [$nodes, $edges] = linear([['id' => 'letter', 'type' => 'document', 'name' => 'Welcome letter', 'config' => ['letter_template_id' => $template->id, 'issue' => 'issue']]]);
    $instance = $this->engine->start(publishWorkflow($nodes, $edges), $employee);
    $letter = Letter::query()->findOrFail($instance->contextValue('letter_id'));
    expect($instance->status)->toBe(InstanceStatus::Completed)->and($letter->employee_id)->toBe($employee->id)->and($letter->status)->toBe('issued')
        ->and(WorkflowAction::query()->where('workflow_instance_id', $instance->id)->where('action', 'document.generated')->exists())->toBeTrue();

    [$nodes, $edges] = linear([['id' => 'letter', 'type' => 'document', 'name' => 'Old document step', 'config' => []]]);
    $old = $this->engine->start(publishWorkflow($nodes, $edges), $employee);
    expect($old->status)->toBe(InstanceStatus::Completed)->and(Letter::query()->count())->toBe(1)
        ->and(WorkflowAction::query()->where('workflow_instance_id', $old->id)->where('action', 'skipped')->exists())->toBeTrue();
});

it('routes approvals to the functional manager and the HR business partner from the reporting relationships', function () {
    $line = employeeWithUser();
    $functional = employeeWithUser();
    $partner = employeeWithUser();
    $employee = employeeWithUser($line);
    ReportingRelationship::create(['employee_id' => $employee->id, 'manager_id' => $functional->id, 'type' => 'functional', 'is_primary' => false, 'effective_from' => '2025-01-01']);
    ReportingRelationship::create(['employee_id' => $employee->id, 'manager_id' => $partner->id, 'type' => 'hrbp', 'is_primary' => false, 'effective_from' => '2025-01-01']);

    [$nodes, $edges] = linear([
        ['id' => 'fm', 'type' => 'approval', 'name' => 'Functional', 'config' => ['mode' => 'single', 'approver' => ['type' => 'functional_manager']]],
        ['id' => 'bp', 'type' => 'approval', 'name' => 'HRBP', 'config' => ['mode' => 'single', 'approver' => ['type' => 'hrbp']]],
    ]);
    $instance = $this->engine->start(publishWorkflow($nodes, $edges), $employee);
    expect($instance->tasks()->sole()->assignee_id)->toBe($functional->user_id);
    $this->engine->completeTask($instance->tasks()->sole(), 'approved', null, $functional->user);
    expect($instance->tasks()->where('node_id', 'bp')->sole()->assignee_id)->toBe($partner->user_id);

    // Nobody in that relationship: the node's fallback applies, as for any approver.
    [$nodes, $edges] = linear([['id' => 'bp', 'type' => 'approval', 'name' => 'HRBP', 'config' => ['mode' => 'single', 'approver' => ['type' => 'hrbp'], 'fallback' => ['type' => 'user', 'user_id' => $this->hr->id]]]]);
    $other = $this->engine->start(publishWorkflow($nodes, $edges), $line);
    expect($other->tasks()->sole()->assignee_id)->toBe($this->hr->id);
});

it('decides automatically at the last level after the turnaround time, and only there', function () {
    $employee = employeeWithUser();
    $approve = fn (string $id, array $escalation = []) => ['id' => $id, 'type' => 'approval', 'name' => strtoupper($id), 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $this->approver->id], 'escalation' => $escalation]];
    $auto = [['after_hours' => 24, 'action' => 'auto_decide', 'decision' => 'approve']];

    [$nodes, $edges] = linear([$approve('first', $auto), $approve('last')]);
    expect(app(WorkflowDefinition::class)->validate(['nodes' => $nodes, 'edges' => $edges]))->toContain('Node [first]: "Decide automatically" is allowed only on the last approval step.')
        ->and(fn () => publishWorkflow($nodes, $edges))->toThrow(WorkflowException::class, 'last approval step');

    [$nodes, $edges] = linear([$approve('first'), $approve('last', $auto)]);
    $instance = $this->engine->start(publishWorkflow($nodes, $edges), $employee);
    $this->engine->completeTask($instance->tasks()->sole(), 'approved', null, $this->approver);
    $last = $instance->tasks()->where('node_id', 'last')->sole();

    $this->travel(23)->hours();
    app(EscalationEngine::class)->run();
    expect($last->fresh()->status)->toBe(TaskStatus::Pending);

    $this->travel(2)->hours();
    app(EscalationEngine::class)->run();
    expect($last->fresh()->status)->toBe(TaskStatus::Approved)->and($last->fresh()->completed_by)->toBeNull()
        ->and($instance->fresh()->status)->toBe(InstanceStatus::Completed)->and($instance->fresh()->outcome)->toBe('approved')
        ->and(WorkflowAction::query()->where('workflow_instance_id', $instance->id)->where('action', 'task.auto_decided')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('entity_type', WorkflowInstance::class)->where('entity_id', (string) $instance->id)->where('action', 'APPROVED')->count())->toBe(2);
});

it('starts every matching workflow by default, and only the first by priority when first_match_only is on', function () {
    $employee = employeeWithUser(null, ['payroll.request']);
    [$nodes, $edges] = linear([['id' => 'note', 'type' => 'wait', 'name' => 'Wait', 'config' => ['hours' => 1]]]);
    $late = publishWorkflow($nodes, $edges, ['trigger_event' => 'payroll.loan_requested', 'priority' => 20]);
    $early = publishWorkflow($nodes, $edges, ['trigger_event' => 'payroll.loan_requested', 'priority' => 10, 'start_conditions' => [['field' => 'employment_type_id', 'operator' => 'equals', 'value' => '999999']]]);

    $loan = app(Loans::class)->request($employee, 'personal', 1000, 1, 'One', null);
    expect($late->instances()->count())->toBe(1)->and($early->instances()->count())->toBe(0);

    app(SettingsRepository::class)->set('workflow.first_match_only', true, 'test');
    $loan->update(['status' => 'cancelled']);
    $early->update(['start_conditions' => []]);
    app(Loans::class)->request($employee, 'personal', 1000, 1, 'Two', null);
    expect($early->instances()->count())->toBe(1)->and($late->instances()->count())->toBe(1);

    app(SettingsRepository::class)->set('workflow.first_match_only', false, 'test');
    Loan::query()->where('status', 'pending')->update(['status' => 'cancelled']);
    app(Loans::class)->request($employee, 'personal', 1000, 1, 'Three', null);
    expect($early->instances()->count())->toBe(2)->and($late->instances()->count())->toBe(2);
});

it('offers the new triggers, request types, approvers and escalation action in the builder configuration', function () {
    expect(config('peopleos.workflows.trigger_events'))->toHaveKeys(['payroll.loan_requested', 'payroll.claim_submitted', 'payroll.variable_pay_requested', 'payroll.tax_declaration_submitted', 'compensation.change.submitted', 'compensation.change.reviewed', 'exit.resignation_submitted'])
        ->and(config('peopleos.workflows.subject_types'))->toHaveKeys([AttendanceRegularisation::class, Loan::class, CompensationChange::class, Letter::class])
        ->and(config('peopleos.workflows.approver_types'))->toHaveKeys(['functional_manager', 'hrbp'])
        ->and(config('peopleos.workflows.escalation_actions'))->toHaveKey('auto_decide')
        ->and(config('peopleos.notifications.events'))->toContain('payroll.claim_submitted')
        ->and(config('peopleos.notifications.events'))->toContain('exit.resignation_accepted')
        ->and(config('peopleos.notifications.events'))->toContain('workflow.task.delegated');
});
