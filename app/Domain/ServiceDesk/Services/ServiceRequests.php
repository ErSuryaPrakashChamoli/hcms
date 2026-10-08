<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\ServiceDesk\Contracts\ServiceDomainAction;
use App\Domain\ServiceDesk\Events\ServiceDeskEvent;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Domain\ServiceDesk\Models\ServiceDefinitionVersion;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\ServiceDesk\Models\TicketComment;
use App\Domain\ServiceDesk\Models\TicketTransition;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Support\Numbering\NumberSequences;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 12: request intake — the one way an HR service request (or a legacy "Ask HR" ticket) is
 * created.
 *
 * **Checks:**
 * - who may raise what for whom: self-service for one's own record only; managers for people they
 *   manage; HR in scope;
 * - catalogue availability and eligibility;
 * - the form (Configuration Form plus domain action rules; unknown fields dropped);
 * - the attachment rule.
 *
 * **Creation:**
 * - It is idempotent per employee and key: a retry returns the original. The employee row lock
 *   serialises concurrent submissions, and a unique index is the backstop.
 * - It draws a locked number and pins the service version.
 * - It starts the SLA, assigns the team and agent, and hands off to the domain at submission or
 *   starts the approval workflow.
 * - It audits, records a timeline reference, and notifies without values.
 *
 * Lock order inside the submission: employee → number sequence → the owning domain's locks → audit
 * chain (always last).
 */
final class ServiceRequests
{
    public function __construct(
        private readonly ServiceCatalogue $catalogue,
        private readonly ServiceForms $forms,
        private readonly DomainActions $actions,
        private readonly DomainActionExecutor $executor,
        private readonly CaseAssignment $assignment,
        private readonly CaseAttachments $attachments,
        private readonly SlaClock $sla,
        private readonly NumberSequences $numbers,
        private readonly AuditRecorder $audit,
        private readonly WorkflowEngine $workflows,
        private readonly RequestLifecycle $lifecycle,
        private readonly AccessScopes $scopes,
    ) {}

    /**
     * @param  array<string, mixed>  $data  form data
     * @param  array{subject?: string, description?: string, priority?: string, source?: string, idempotency_key?: string, attachment?: UploadedFile|string|null, attachment_name?: string, draft?: bool}  $options
     */
    public function submit(ServiceDefinition $service, Employee $employee, User $requester, array $data = [], array $options = []): Ticket
    {
        $source = $options['source'] ?? ((int) $employee->user_id === (int) $requester->id ? 'web' : 'hr');
        $version = $service->isActive() ? $this->catalogue->versionOn($service) : null;
        if ($version === null) {
            throw new ServiceDeskRuleViolation('This service is not available now.');
        }
        $handler = $this->actions->find($version->domain_action);
        $this->authorise($requester, $employee, $source, $version, $handler);
        if (! $this->catalogue->eligible($version, $employee)) {
            throw new ServiceDeskRuleViolation('This service is not available for this employee.');
        }
        $clean = $this->forms->validate($version, $employee, $data);
        $attachment = $options['attachment'] ?? null;
        if ($version->attachment_rule === 'required' && blank($attachment)) {
            throw new ServiceDeskRuleViolation('This service needs an attachment.');
        }
        if ($version->attachment_rule === 'none' && filled($attachment)) {
            throw new ServiceDeskRuleViolation('This service does not take attachments.');
        }
        $priority = array_key_exists((string) ($options['priority'] ?? ''), config('peopleos.servicedesk.priorities')) ? $options['priority'] : $version->default_priority;

        return $this->create($employee, $requester, [
            'ticket_category_id' => $service->ticket_category_id ?? $this->fallbackCategory()->id,
            'service_definition_id' => $service->id,
            'service_definition_version_id' => $version->id,
            'source' => $source,
            'subject' => Str::limit(trim((string) ($options['subject'] ?? '')) ?: $service->name, 250, ''),
            'description' => trim((string) ($options['description'] ?? '')) ?: $service->name,
            'form_data' => $clean,
            'priority' => $priority,
            'confidentiality' => $version->confidentiality,
            'visible_to_employee' => $version->visible_to_employee,
            'domain_action' => $version->domain_action,
        ], $version, $handler, $options);
    }

    /** Legacy "Ask HR" ticket in a request category (no catalogue service, no form, no domain action). */
    public function openGeneric(Employee $employee, TicketCategory $category, string $subject, string $description, string $priority = 'normal', ?User $raiser = null, ?string $idempotencyKey = null, string $source = ''): Ticket
    {
        if ($category->status->value !== 'active') {
            throw new ServiceDeskRuleViolation('That request type is not available.');
        }
        $raiser ??= auth()->user();
        if ($raiser === null) {
            throw new ServiceDeskRuleViolation('A request is raised by a person.');
        }
        $source = $source !== '' ? $source : ((int) $employee->user_id === (int) $raiser->id ? 'web' : 'hr');
        if ($source === 'web' && (int) $employee->user_id !== (int) $raiser->id) {
            throw new ServiceDeskRuleViolation('Self-service requests are for your own record only.');
        }
        if ($source === 'hr' && ! ($raiser->hasPermission('servicedesk.agent') && $this->scopes->allows($raiser, $employee))) {
            throw new ServiceDeskRuleViolation('That employee is outside your service desk scope.');
        }

        return $this->create($employee, $raiser, [
            'ticket_category_id' => $category->id, 'source' => $source, 'subject' => Str::limit($subject, 250, ''), 'description' => $description,
            'priority' => array_key_exists($priority, config('peopleos.servicedesk.priorities')) ? $priority : 'normal', 'confidentiality' => 'standard',
            'visible_to_employee' => true,
        ], null, null, ['idempotency_key' => $idempotencyKey], $category);
    }

    /** Submit a saved draft (re-validated against the version it was drafted under). */
    public function submitDraft(Ticket $draft, User $requester, ?int $expectedVersion = null): Ticket
    {
        if ($draft->status !== 'draft' || (int) $draft->raised_by !== (int) $requester->id) {
            throw new ServiceDeskRuleViolation('Only the person who drafted a request submits it.');
        }
        $version = ServiceDefinitionVersion::query()->with(['service', 'formVersion', 'slaPolicy'])->findOrFail($draft->service_definition_version_id);
        $current = $this->catalogue->versionOn($version->service_definition_id);
        if ($current === null || $current->id !== $version->id) {
            throw new ServiceDeskRuleViolation('This service has changed since the draft was saved; start a new request.');
        }
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($draft->employee_id);
        $handler = $this->actions->find($version->domain_action);
        $this->authorise($requester, $employee, $draft->source, $version, $handler);
        $clean = $this->forms->validate($version, $employee, (array) $draft->form_data);

        return DB::transaction(function () use ($draft, $requester, $expectedVersion, $version, $handler, $employee, $clean) {
            $this->lockEmployee($employee);
            $ticket = Ticket::query()->withoutGlobalScope(AccessScope::class)->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($ticket->status !== 'draft' || ($expectedVersion !== null && $ticket->lock_version !== $expectedVersion)) {
                throw new ServiceDeskRuleViolation('This draft changed since you opened it; reload and try again.');
            }
            $ticket->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'form_data' => $clean, 'lock_version' => $ticket->lock_version + 1]);
            $steps = [['draft', 'submitted']];
            $this->sla->start($ticket, $version->slaPolicy, null);
            $this->assignment->initial($ticket, $version, $ticket->loaded('category'));
            if ($ticket->assignee_id) {
                $ticket->status = 'assigned';
                $steps[] = ['submitted', 'assigned'];
            }
            $ticket->withoutAuditing()->save();
            $steps = [...$steps, ...$this->afterSubmit($ticket, $version, $handler, $employee, $requester)];
            $this->record($ticket, $requester, $steps, AuditAction::RequestSubmitted);

            return $ticket;
        });
    }

    /** @param  array<string, mixed>  $attributes */
    private function create(Employee $employee, User $requester, array $attributes, ?ServiceDefinitionVersion $version, ?ServiceDomainAction $handler, array $options, ?TicketCategory $category = null): Ticket
    {
        $key = filled($options['idempotency_key'] ?? null) ? Str::limit((string) $options['idempotency_key'], 64, '') : null;
        if ($key !== null && ($existing = $this->existing($employee, $key, $requester)) !== null) {
            return $existing;
        }
        $draft = (bool) ($options['draft'] ?? false);
        $this->numbers->ensure('TKT', NumberSequences::highest(Ticket::class));

        try {
            return DB::transaction(function () use ($employee, $requester, $attributes, $version, $handler, $options, $category, $key, $draft) {
                $this->lockEmployee($employee);
                if ($key !== null && ($existing = $this->existing($employee, $key, $requester, locking: true)) !== null) {
                    return $existing;
                }
                $id = (string) Str::ulid();
                $ticket = new Ticket($attributes + [
                    'number' => $this->numbers->next('TKT', NumberSequences::highest(Ticket::class)),
                    'employee_id' => $employee->id, 'raised_by' => $requester->id, 'status' => $draft ? 'draft' : 'submitted',
                    'submitted_at' => $draft ? null : now(), 'status_changed_at' => now(), 'idempotency_key' => $key,
                    'correlation_id' => $id, 'operation_id' => (string) Str::ulid(),
                    'domain_action_status' => null,
                ]);
                $ticket->setRelation('category', $category ?? TicketCategory::query()->find($attributes['ticket_category_id']));
                if ($ticket->confidentiality === 'restricted' && $requester->hasPermission('servicedesk.confidential') && (int) $employee->user_id !== (int) $requester->id) {
                    // Whoever opens a restricted case on HR's side owns it (explicit access from the start).
                    $ticket->owner_id = $requester->id;
                }
                $steps = [[null, $ticket->status]];
                if (! $draft) {
                    $this->sla->start($ticket, $version?->slaPolicy, $category);
                    $this->assignment->initial($ticket, $version, $ticket->category);
                    if ($ticket->assignee_id) {
                        $ticket->status = 'assigned';
                        $steps[] = ['submitted', 'assigned'];
                    }
                }
                $ticket->withoutAuditing()->save();
                if (! $draft) {
                    $steps = [...$steps, ...$this->afterSubmit($ticket, $version, $handler, $employee, $requester)];
                }
                if ($version === null && $category?->workflow_key) {
                    $this->startCategoryWorkflow($ticket, $category, $employee, $requester);
                }
                $comment = filled($options['attachment'] ?? null) ? $this->attach($ticket, $requester, $options['attachment'], $options['attachment_name'] ?? null) : null;
                $this->record($ticket, $requester, $steps, AuditAction::RequestCreated, $comment);

                return $ticket;
            });
        } catch (UniqueConstraintViolationException $e) {
            // The unique (tenant, employee, key) index caught a concurrent duplicate: return the original.
            if ($key !== null && ($existing = $this->existing($employee, $key, $requester)) !== null) {
                return $existing;
            }
            throw $e;
        }
    }

    /**
     * After a request is submitted (inside its transaction):
     * - an on_submit action is handed to its domain now;
     * - otherwise, the approval workflow starts if the service needs one;
     * - else a domain change is ready for an HR executor.
     *
     * @return list<array{0: string, 1: string}> status steps taken
     */
    private function afterSubmit(Ticket $ticket, ?ServiceDefinitionVersion $version, ?ServiceDomainAction $handler, Employee $employee, User $requester): array
    {
        $steps = [];
        $from = $ticket->status;
        if ($handler !== null && $handler->timing() === 'on_submit') {
            $record = $this->executor->runOnSubmit($ticket, $handler, $employee, (array) $ticket->form_data, $requester);
            $pending = in_array((string) $record->getAttribute('status'), ['pending', 'pending_approval', 'requested', 'submitted'], true);
            $ticket->forceFill([
                'domain_action_status' => 'executed', 'domain_reference_type' => $record->getMorphClass(), 'domain_reference_id' => $record->getKey(),
                'domain_action_executed_at' => now(), 'domain_action_executed_by' => $requester->id, 'status' => $pending ? 'awaiting_approval' : 'in_progress',
            ] + $this->executor->purge($ticket));
        } elseif ($version?->approval_required) {
            $workflow = Workflow::query()->where('key', $version->workflow_key)->where('status', 'active')->first();
            if ($workflow === null || ! $workflow->published()->exists()) {
                throw new ServiceDeskRuleViolation('The approval workflow for this service is not available; try again later or contact HR.');
            }
            $instance = $this->workflows->start($workflow, $ticket, ['ticket' => ['number' => $ticket->number, 'service' => $version->service?->code, 'employee_id' => $employee->id]], $requester);
            $ticket->forceFill(['workflow_instance_id' => $instance->id, 'status' => 'awaiting_approval', 'domain_action_status' => $handler ? 'awaiting_approval' : null]);
        } elseif ($handler !== null) {
            $ticket->forceFill(['domain_action_status' => 'ready']);
        }
        if ($ticket->status !== $from) {
            $steps[] = [$from, $ticket->status];
            $this->sla->onTransition($ticket, $from, $ticket->status);
        }
        if ($ticket->isDirty()) {
            $ticket->withoutAuditing()->save();
        }

        return $steps;
    }

    /** Legacy categories may name a workflow (automation, not an approval gate): it starts with the ticket as subject. */
    private function startCategoryWorkflow(Ticket $ticket, TicketCategory $category, Employee $employee, User $requester): void
    {
        $workflow = Workflow::query()->where('key', $category->workflow_key)->where('status', 'active')->first();
        if ($workflow !== null && $workflow->published()->exists()) {
            $instance = $this->workflows->start($workflow, $ticket, ['ticket' => ['number' => $ticket->number, 'category' => $category->code, 'employee_id' => $employee->id]], $requester);
            $ticket->forceFill(['workflow_instance_id' => $instance->id])->withoutAuditing()->save();
        }
    }

    /** Status history, audit, timeline reference and the "created" notification — after all domain work. */
    private function record(Ticket $ticket, User $requester, array $steps, AuditAction $action, ?TicketComment $comment = null): void
    {
        foreach ($steps as [$from, $to]) {
            TicketTransition::query()->create(['ticket_id' => $ticket->id, 'from_status' => $from, 'to_status' => $to, 'actor_id' => $requester->id, 'via' => $from === null || $from === 'draft' ? 'user' : 'system', 'operation_id' => $ticket->operation_id]);
        }
        $meta = ['service' => $ticket->loaded('service')?->code, 'version_id' => $ticket->service_definition_version_id, 'category' => $ticket->loaded('category')?->code, 'priority' => $ticket->priority, 'source' => $ticket->source, 'confidentiality' => $ticket->confidentiality, 'correlation_id' => $ticket->correlation_id];
        $this->audit->record($action, 'servicedesk', $ticket, [['field' => 'status', 'before' => null, 'after' => $ticket->status]], null, actor: $requester, metadata: array_filter($meta));
        if ($ticket->status !== 'draft' && $action === AuditAction::RequestCreated) {
            $this->audit->record(AuditAction::RequestSubmitted, 'servicedesk', $ticket, [], null, actor: $requester, metadata: ['correlation_id' => $ticket->correlation_id]);
        }
        if ($ticket->assignee_id) {
            $this->audit->record(AuditAction::RequestAssigned, 'servicedesk', $ticket, [['field' => 'assignee_id', 'before' => null, 'after' => $ticket->assignee_id]], null, actor: $requester, metadata: ['via' => 'auto']);
        }
        if ($ticket->domain_action_status === 'executed') {
            $this->audit->record(AuditAction::RequestStatusChanged, 'servicedesk', $ticket, [], null, actor: $requester, metadata: ['event' => 'DOMAIN_ACTION_EXECUTED', 'domain_action' => $ticket->domain_action, 'reference' => class_basename((string) $ticket->domain_reference_type).' #'.$ticket->domain_reference_id, 'operation_id' => $ticket->operation_id]);
        }
        if ($comment !== null) {
            $this->audit->record(AuditAction::AttachmentUploaded, 'servicedesk', $ticket, [], null, actor: $requester, metadata: ['comment_id' => $comment->id, 'file' => $comment->attachment_name, 'sha256' => $comment->attachment_sha256]);
        }
        if ($ticket->status === 'draft') {
            return;
        }
        if ($ticket->confidentiality === 'standard' && $ticket->visible_to_employee) {
            $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->find($ticket->employee_id);
            app(Timeline::class)->record($employee, 'service_request', 'HR request '.$ticket->number.' raised: '.$ticket->serviceName(), now(), null, $ticket, ['number' => $ticket->number, 'service' => $ticket->serviceCode()]);
        }
        $recipients = $ticket->assignee_id ? [$ticket->assignee_id] : [];
        ServiceDeskEvent::dispatch('servicedesk.ticket.created', $ticket, $this->lifecycle->context($ticket), $recipients);
        if ($ticket->status === 'awaiting_approval' && $ticket->workflow_instance_id) {
            ServiceDeskEvent::dispatch('servicedesk.ticket.approval_required', $ticket, $this->lifecycle->context($ticket), []);
        }
    }

    private function attach(Ticket $ticket, User $author, UploadedFile|string $file, ?string $name): TicketComment
    {
        $stored = $this->attachments->store($ticket, $file, $name);

        return TicketComment::query()->create(['ticket_id' => $ticket->id, 'author_id' => $author->id, 'body' => 'Attachment', 'visibility' => 'employee'] + $stored);
    }

    private function authorise(User $requester, Employee $employee, string $source, ServiceDefinitionVersion $version, ?ServiceDomainAction $handler): void
    {
        if (! $requester->isActive()) {
            throw new ServiceDeskRuleViolation('Only an active user can raise a request.');
        }
        [$audience, $permission] = match ($source) {
            'web' => ['employee', 'servicedesk.request'],
            'manager' => ['manager', 'servicedesk.team'],
            'hr', 'api' => ['hr', 'servicedesk.agent'],
            default => throw new ServiceDeskRuleViolation('Unknown request source.'),
        };
        if (! $requester->hasPermission($permission) || ! $version->availableTo($audience)) {
            throw new ServiceDeskRuleViolation('You cannot raise this service '.match ($audience) {
                'manager' => 'for a team member', 'hr' => 'on an employee\'s behalf', default => 'for yourself',
            }.'.');
        }
        match ($audience) {
            'employee' => (int) $employee->user_id === (int) $requester->id ?: throw new ServiceDeskRuleViolation('Self-service requests are for your own record only.'),
            'manager' => app(PerformanceRelationships::class)->manages(app(PerformanceRelationships::class)->forUser($requester), $employee->id) ?: throw new ServiceDeskRuleViolation('You can raise this only for people you manage.'),
            'hr' => $this->scopes->allows($requester, $employee) && (int) $employee->user_id !== (int) $requester->id ?: throw new ServiceDeskRuleViolation('That employee is outside your service desk scope.'),
        };
        $handler?->authorizeRequest($requester, $employee, $source === 'api' ? 'hr' : $source);
    }

    /** @param  bool  $locking  under the employee lock: a locking read sees rows committed after this transaction's snapshot */
    private function existing(Employee $employee, string $key, User $requester, bool $locking = false): ?Ticket
    {
        $ticket = Ticket::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('idempotency_key', $key)
            ->when($locking, fn ($q) => $q->lockForUpdate())->first();
        if ($ticket !== null && (int) $ticket->raised_by !== (int) $requester->id) {
            throw new ServiceDeskRuleViolation('That idempotency key was already used for another request.');
        }

        return $ticket;
    }

    private function lockEmployee(Employee $employee): void
    {
        Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->id)->lockForUpdate()->firstOrFail();
    }

    private function fallbackCategory(): TicketCategory
    {
        return TicketCategory::query()->where('code', 'OTHER')->first() ?? TicketCategory::query()->orderBy('id')->firstOrFail();
    }
}
