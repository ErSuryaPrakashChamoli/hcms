<?php

namespace App\Domain\Letters\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A generated letter (§40): rendered content frozen at generation, approval, issue as an employee document. */
#[Fillable(['tenant_id', 'number', 'employee_id', 'letter_template_id', 'type', 'subject', 'body', 'context', 'status', 'requested_by', 'approved_by', 'approved_at', 'issued_by', 'issued_at', 'document_id', 'review_note', 'source_type', 'source_id'])]
class Letter extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['context' => 'array', 'approved_at' => 'datetime', 'issued_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'letters';
    }

    public function auditLabel(): string
    {
        return "{$this->number} {$this->subject}";
    }

    public function auditSensitiveAttributes(): array
    {
        return ['body', 'context'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LetterTemplate::class, 'letter_template_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'document_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function isIssued(): bool
    {
        return $this->status === 'issued';
    }
}
