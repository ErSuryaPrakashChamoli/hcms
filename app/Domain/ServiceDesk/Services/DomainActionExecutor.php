<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\ServiceDesk\Contracts\ServiceDomainAction;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\Workflow\Models\WorkflowTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 12: Service Request → (Approval) → existing domain action, exactly once.
 *
 * **Separation of duties.** The executor:
 * - holds the domain's permission and can work the case;
 * - is never the requester, the employee concerned, or an approver.
 *
 * **Execution:**
 * - It runs under the request row lock, in one transaction with the request's status. A retry
 *   (repeated click, job retry, concurrent executor) finds it executed and returns the stored
 *   reference, so there is no second mutation.
 * - A failure rolls back both.
 * - The domain audit carries the request's operation id (AuditRecorder::withinOperation) and its
 *   number (approval reference).
 *
 * **Result.** The request keeps the resulting record's reference and purges the sensitive values it
 * held.
 */
final class DomainActionExecutor
{
    public function __construct(
        private readonly DomainActions $actions,
        private readonly CaseAccess $access,
        private readonly RequestLifecycle $lifecycle,
        private readonly AuditRecorder $audit,
        private readonly ServiceForms $forms,
    ) {}

    /** at submission (on_submit actions): the requester's own request in the owning domain */
    public function runOnSubmit(Ticket $ticket, ServiceDomainAction $handler, Employee $employee, array $data, User $requester): Model
    {
        return $this->audit->withinOperation((string) $ticket->operation_id, fn () => $handler->execute($ticket, $employee, $data, $requester, new ChangeOrigin('service_desk', $ticket->number, $ticket->operation_id)));
    }

    /** @param  array<string, mixed>  $input  the handler's execution fields (link actions) */
    public function execute(Ticket $ticket, User $executor, array $input = [], ?int $expectedVersion = null): Ticket
    {
        $handler = $this->actions->get((string) $ticket->domain_action);
        if ($handler->timing() === 'on_submit') {
            throw new ServiceDeskRuleViolation($handler->label().' is handed to its domain when the request is submitted.');
        }
        if ($handler->executorPermission() !== null && ! $executor->hasPermission($handler->executorPermission())) {
            throw new ServiceDeskRuleViolation('Executing this change needs '.$handler->executorPermission().'.');
        }
        if (! $this->access->canWork($executor, $ticket)) {
            throw new ServiceDeskRuleViolation('You cannot work this case.');
        }

        return DB::transaction(function () use ($ticket, $executor, $input, $expectedVersion, $handler) {
            $fresh = Ticket::query()->withoutGlobalScope(AccessScope::class)->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($fresh->domain_action_status === 'executed') {
                return $fresh; // a retry: executed once already, nothing more to do
            }
            if ($expectedVersion !== null && $fresh->lock_version !== $expectedVersion) {
                throw new ServiceDeskRuleViolation('This request changed since you opened it; reload and try again.');
            }
            if (! $this->access->canWork($executor, $fresh)) {
                throw new ServiceDeskRuleViolation('You cannot work this case.');
            }
            if ($fresh->domain_action_status !== 'ready' || ! $fresh->isOpen()) {
                throw new ServiceDeskRuleViolation('This change is not ready to execute ('.config('peopleos.servicedesk.domain_action_statuses.'.$fresh->domain_action_status, (string) $fresh->domain_action_status).').');
            }
            $this->assertSeparation($fresh, $executor);
            $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($fresh->employee_id);
            $fields = $handler->executionFields($employee, $executor);
            $data = (array) ($fresh->form_data ?? []) + array_intersect_key($input, $fields);

            try {
                $record = $this->audit->withinOperation((string) $fresh->operation_id, fn () => $handler->execute($fresh, $employee, $data, $executor, new ChangeOrigin('service_desk', $fresh->number, $fresh->operation_id)));
            } catch (ServiceDeskRuleViolation $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new ServiceDeskRuleViolation($e->getMessage(), previous: $e);
            }

            $changes = [
                'domain_action_status' => 'executed', 'domain_reference_type' => $record->getMorphClass(), 'domain_reference_id' => $record->getKey(),
                'domain_action_executed_at' => now(), 'domain_action_executed_by' => $executor->id,
            ] + $this->purge($fresh);
            $resolution = $handler->label().' applied ('.class_basename($record).' #'.$record->getKey().').';
            $employeeUser = $employee->user_id;

            return $this->lifecycle->move($fresh, 'resolved', $executor, AuditAction::RequestResolved, $resolution, $changes + ['resolution' => $resolution],
                notify: ['event' => 'servicedesk.ticket.resolved', 'recipients' => array_filter([$employeeUser, (int) $fresh->raised_by !== (int) $employeeUser ? $fresh->raised_by : null])],
                metadata: ['event' => 'DOMAIN_ACTION_EXECUTED', 'domain_action' => $fresh->domain_action, 'operation_id' => $fresh->operation_id]);
        });
    }

    /** User ids that approved the request (workflow decisions and the recorded approver). @return list<int> */
    public function approvers(Ticket $ticket): array
    {
        $ids = $ticket->workflow_instance_id
            ? WorkflowTask::query()->where('workflow_instance_id', $ticket->workflow_instance_id)->where('decision', 'approved')->pluck('completed_by')->filter()->map(fn ($id) => (int) $id)->all()
            : [];

        return array_values(array_unique(array_filter([...$ids, $ticket->approved_by ? (int) $ticket->approved_by : null])));
    }

    /** The values a domain change carried, removed once it is decided (executed, rejected, cancelled). */
    public function purge(Ticket $ticket): array
    {
        $data = (array) ($ticket->form_data ?? []);
        if ($data === [] || $ticket->form_data_purged_at !== null) {
            return [];
        }
        $fields = $this->forms->fieldsFor($ticket);
        $kept = array_filter($data, fn ($v, string $key) => ($fields[$key]['class'] ?? 'sensitive') === 'standard' && ($fields[$key]['source'] ?? 'action') === 'form', ARRAY_FILTER_USE_BOTH);

        return ['form_data' => $kept, 'form_data_purged_at' => now()];
    }

    private function assertSeparation(Ticket $ticket, User $executor): void
    {
        $employeeUser = Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($ticket->employee_id)->value('user_id');
        if (in_array((int) $executor->id, array_map('intval', array_filter([$ticket->raised_by, $employeeUser])), true)) {
            throw new ServiceDeskRuleViolation('You requested this change (or it is your own record); someone else must execute it.');
        }
        if (in_array((int) $executor->id, $this->approvers($ticket), true)) {
            throw new ServiceDeskRuleViolation('You approved this change; someone else must execute it.');
        }
    }
}
