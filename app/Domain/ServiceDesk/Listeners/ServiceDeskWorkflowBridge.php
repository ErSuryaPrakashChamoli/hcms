<?php

namespace App\Domain\ServiceDesk\Listeners;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Domain\ServiceDesk\Services\DomainActionExecutor;
use App\Domain\ServiceDesk\Services\DomainActions;
use App\Domain\ServiceDesk\Services\RequestLifecycle;
use App\Domain\Workflow\Events\WorkflowCompleted;
use App\Domain\Workflow\Models\WorkflowTask;
use Illuminate\Support\Facades\DB;

/**
 * Phase 12: a service request's approval runs in the existing workflow engine (no second approval
 * engine). When the instance it is pinned to completes, the outcome decides the request. The checks
 * run under the request lock:
 * - **approved by someone other than the requester or the employee concerned:** a domain change becomes
 *   ready for an HR executor (who must be a third person), and a plain request goes back to work;
 * - **approved by the requester or the employee concerned:** not accepted (separation of duties); the
 *   refusal is audited, and HR may restart approval;
 * - **rejected:** the request is resolved as not approved and any pending values are purged.
 */
final class ServiceDeskWorkflowBridge
{
    public function __construct(private readonly RequestLifecycle $lifecycle, private readonly DomainActionExecutor $executor, private readonly CaseAccess $access, private readonly DomainActions $actions) {}

    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance;
        $subject = $instance->subject;
        if (! $subject instanceof Ticket || (int) $subject->workflow_instance_id !== (int) $instance->id || $subject->status !== 'awaiting_approval') {
            return;
        }
        DB::transaction(function () use ($subject, $instance) {
            $ticket = Ticket::query()->withoutGlobalScope(AccessScope::class)->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            if ((int) $ticket->workflow_instance_id !== (int) $instance->id || $ticket->status !== 'awaiting_approval') {
                return;
            }
            $approvers = WorkflowTask::query()->where('workflow_instance_id', $instance->id)->where('decision', 'approved')->pluck('completed_by')->filter()->map(fn ($id) => (int) $id);
            $employeeUser = (int) Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($ticket->employee_id)->value('user_id');
            $requesters = array_filter([(int) $ticket->raised_by, $employeeUser]);

            if ($instance->outcome === 'approved' && $approvers->intersect($requesters)->isNotEmpty()) {
                $this->lifecycle->move($ticket, 'in_progress', null, AuditAction::Rejected, 'Workflow approval by the requester is not accepted (separation of duties)',
                    ['domain_action_status' => 'refused'], 'workflow', metadata: ['event' => 'REQUEST_APPROVAL_SOD_REFUSED', 'workflow_instance_id' => $instance->id]);

                return;
            }
            if ($instance->outcome === 'approved') {
                $changes = ['approved_by' => $approvers->last(), 'approved_at' => now()] + ($ticket->domain_action ? ['domain_action_status' => 'ready'] : []);
                $this->lifecycle->move($ticket, 'in_progress', null, AuditAction::Approved, 'Approved in workflow run #'.$instance->id, $changes, 'workflow',
                    notify: ['event' => $ticket->domain_action ? 'servicedesk.ticket.ready_to_execute' : 'servicedesk.ticket.assigned', 'recipients' => $this->executors($ticket, $approvers->all(), $requesters)],
                    metadata: ['event' => 'REQUEST_APPROVED', 'workflow_instance_id' => $instance->id]);

                return;
            }
            $this->lifecycle->move($ticket, 'resolved', null, AuditAction::RequestResolved, 'Not approved (workflow run #'.$instance->id.')',
                ['resolution' => 'Not approved.', 'domain_action_status' => $ticket->domain_action ? 'rejected' : null] + $this->executor->purge($ticket), 'workflow',
                notify: ['event' => 'servicedesk.ticket.resolved', 'recipients' => $ticket->visible_to_employee ? [$employeeUser, $ticket->raised_by] : [$ticket->raised_by]],
                metadata: ['event' => 'REQUEST_REJECTED', 'workflow_instance_id' => $instance->id]);
        });
    }

    /** The assignee, if they may execute; else up to 25 eligible agents holding the domain permission. @return list<int> */
    private function executors(Ticket $ticket, array $approvers, array $requesters): array
    {
        $permission = $this->actions->find($ticket->domain_action)?->executorPermission();
        $excluded = array_map('intval', [...$approvers, ...$requesters]);
        $ok = fn (User $u) => ! in_array((int) $u->id, $excluded, true) && $this->access->eligibleAgent($u, $ticket) && ($permission === null || $u->hasPermission($permission));
        $assignee = $ticket->assignee_id ? User::query()->find($ticket->assignee_id) : null;
        if ($assignee !== null && $ok($assignee)) {
            return [$assignee->id];
        }

        return User::query()->forCurrentTenant()->get()->filter($ok)->take(25)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }
}
