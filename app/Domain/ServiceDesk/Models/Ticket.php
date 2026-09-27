<?php

namespace App\Domain\ServiceDesk\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An HR service request (§48): new → open → (pending) → resolved → closed, with SLA clocks. */
#[Fillable(['tenant_id', 'number', 'ticket_category_id', 'employee_id', 'raised_by', 'subject', 'description', 'priority', 'status', 'assignee_id', 'first_response_due_at', 'first_responded_at', 'due_at', 'escalated_at', 'resolved_at', 'closed_at', 'resolution', 'satisfaction', 'satisfaction_comment', 'article_id', 'workflow_instance_id'])]
class Ticket extends Model
{
    use Auditable, BelongsToTenant;

    public const OPEN = ['new', 'open', 'pending'];

    protected $attributes = ['status' => 'new', 'priority' => 'normal'];

    protected function casts(): array
    {
        return [
            'first_response_due_at' => 'datetime', 'first_responded_at' => 'datetime', 'due_at' => 'datetime', 'escalated_at' => 'datetime',
            'resolved_at' => 'datetime', 'closed_at' => 'datetime', 'satisfaction' => 'integer',
        ];
    }

    public function auditModule(): string
    {
        return 'servicedesk';
    }

    public function auditLabel(): string
    {
        return "{$this->number} {$this->subject}";
    }

    public function auditSensitiveAttributes(): array
    {
        return ['description', 'resolution', 'satisfaction_comment'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'ticket_category_id');
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

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class)->orderBy('id');
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

    public function isBreached(): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->isPast();
    }
}
