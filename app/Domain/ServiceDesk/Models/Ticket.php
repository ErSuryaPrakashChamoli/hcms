<?php

namespace App\Domain\ServiceDesk\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The one canonical HR service request / case (§48, Phase 12). It is pinned to the catalogue service
 * version it was raised under (or a legacy ticket category).
 *
 * It carries:
 * - its controlled status (RequestLifecycle — never set directly);
 * - team / agent / owner assignment;
 * - confidentiality;
 * - SLA clocks with pause;
 * - escalation level;
 * - the domain-action hand-off (the action key, its status and the resulting record reference — never
 *   the domain data itself).
 *
 * Form data is encrypted at rest and field-classified by the service version. Values for a domain
 * change are purged once the change is executed, rejected or cancelled.
 */
#[Fillable([
    'tenant_id', 'number', 'ticket_category_id', 'service_definition_id', 'service_definition_version_id', 'employee_id', 'raised_by', 'source', 'subject',
    'description', 'form_data', 'form_data_purged_at', 'priority', 'confidentiality', 'visible_to_employee', 'status', 'assignee_id', 'assigned_role_id',
    'owner_id', 'first_response_due_at', 'first_responded_at', 'due_at', 'escalated_at', 'resolved_at', 'resolved_by', 'closed_at', 'resolution',
    'satisfaction', 'satisfaction_comment', 'article_id', 'workflow_instance_id', 'sla_policy_id', 'sla_mode', 'submitted_at', 'acknowledged_at',
    'assigned_at', 'status_changed_at', 'sla_paused_at', 'sla_paused_minutes', 'escalation_level', 'cancelled_at', 'domain_action', 'domain_action_status',
    'domain_reference_type', 'domain_reference_id', 'domain_action_executed_at', 'domain_action_executed_by', 'approved_by', 'approved_at',
    'idempotency_key', 'correlation_id', 'operation_id', 'lock_version',
])]
class Ticket extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    /** Open: the request still needs service (the SLA can run). */
    public const OPEN = ['submitted', 'acknowledged', 'assigned', 'in_progress', 'awaiting_approval', 'waiting_employee', 'waiting_hr'];

    /** Finished: no more service work (resolved can still be reopened or closed). */
    public const DONE = ['resolved', 'closed', 'cancelled'];

    protected $attributes = ['status' => 'submitted', 'priority' => 'normal', 'source' => 'web', 'confidentiality' => 'standard', 'visible_to_employee' => true, 'sla_paused_minutes' => 0, 'escalation_level' => 0, 'lock_version' => 0];

    protected function casts(): array
    {
        return [
            'first_response_due_at' => 'datetime', 'first_responded_at' => 'datetime', 'due_at' => 'datetime', 'escalated_at' => 'datetime',
            'resolved_at' => 'datetime', 'closed_at' => 'datetime', 'satisfaction' => 'integer', 'form_data' => 'encrypted:array',
            'form_data_purged_at' => 'datetime', 'visible_to_employee' => 'boolean', 'submitted_at' => 'datetime', 'acknowledged_at' => 'datetime',
            'assigned_at' => 'datetime', 'status_changed_at' => 'datetime', 'sla_paused_at' => 'datetime', 'sla_paused_minutes' => 'integer',
            'escalation_level' => 'integer', 'cancelled_at' => 'datetime', 'domain_action_executed_at' => 'datetime', 'approved_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function auditModule(): string
    {
        return 'servicedesk';
    }

    public function auditLabel(): string
    {
        // The number only: subjects are free text and may carry personal detail.
        return (string) $this->number;
    }

    public function auditSensitiveAttributes(): array
    {
        return ['description', 'resolution', 'satisfaction_comment', 'form_data', 'subject'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'ticket_category_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceDefinition::class, 'service_definition_id');
    }

    public function serviceVersion(): BelongsTo
    {
        return $this->belongsTo(ServiceDefinitionVersion::class, 'service_definition_version_id');
    }

    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(ServiceSlaPolicy::class, 'sla_policy_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'assigned_role_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'domain_action_executed_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class)->orderBy('id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(TicketTransition::class)->orderBy('id');
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(TicketAccessGrant::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isRestricted(): bool
    {
        return $this->confidentiality === 'restricted';
    }

    public function isPaused(): bool
    {
        return $this->sla_paused_at !== null;
    }

    public function isBreached(): bool
    {
        return $this->isOpen() && ! $this->isPaused() && $this->due_at !== null && $this->due_at->isPast();
    }

    /** A belongs-to relation, loaded once on demand (lazy loading stays disabled elsewhere). */
    public function loaded(string $relation): mixed
    {
        if (! $this->relationLoaded($relation)) {
            $this->setRelation($relation, $this->{$relation}()->first());
        }

        return $this->getRelation($relation);
    }

    /** The name shown in notifications and lists: the service (or category), never the free-text subject. */
    public function serviceName(): string
    {
        return (string) ($this->loaded('service')?->name ?? $this->loaded('category')?->name ?? 'HR request');
    }

    public function serviceCode(): ?string
    {
        return $this->loaded('service')?->code ?? $this->loaded('category')?->code;
    }
}
