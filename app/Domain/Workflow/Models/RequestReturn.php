<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * HROne setup parity P3 (Inbox → Send back): a request an approver returned to its requester with a note. Its subject
 * is the workflow run (status Returned until resubmitted) or the domain request itself, which keeps its pending status
 * meanwhile (its balance reservations, overlap checks and payroll inputs are untouched) and leaves every approver's
 * queue while the return is open. Statuses: open → resubmitted (the requester answered) or closed (decided or withdrawn
 * meanwhile). Written only by RequestReturns; every change audited.
 */
#[Fillable(['tenant_id', 'kind', 'subject_type', 'subject_id', 'subject_label', 'requester_id', 'returned_by', 'returned_at', 'note', 'status', 'response', 'resubmitted_by', 'resubmitted_at'])]
class RequestReturn extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['open' => 'Waiting for the requester', 'resubmitted' => 'Resubmitted', 'closed' => 'Closed'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['returned_at' => 'datetime', 'resubmitted_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'workflow';
    }

    public function auditLabel(): string
    {
        return 'Sent back: '.($this->subject_label ?? $this->kind.' #'.$this->subject_id);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
