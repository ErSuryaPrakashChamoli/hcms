<?php

namespace App\Domain\Grievance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A grievance case (§49). Restricted: only the assignee, users named in access_user_ids, holders of
 * the category's handler roles, and the raising employee (for their own, non-anonymous case) can read it.
 */
#[Fillable(['tenant_id', 'number', 'grievance_category_id', 'employee_id', 'is_anonymous', 'subject', 'details', 'severity', 'status', 'assignee_id', 'access_user_ids', 'due_on', 'resolution', 'resolved_at', 'closed_at'])]
class Grievance extends Model
{
    use Auditable, BelongsToTenant;

    public const OPEN = ['submitted', 'under_review', 'investigating', 'action_taken'];

    protected $attributes = ['status' => 'submitted', 'severity' => 'medium'];

    protected function casts(): array
    {
        return ['is_anonymous' => 'boolean', 'access_user_ids' => 'array', 'due_on' => 'date', 'resolved_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'grievance';
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    public function auditSensitiveAttributes(): array
    {
        return ['subject', 'details', 'resolution', 'employee_id'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(GrievanceCategory::class, 'grievance_category_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(GrievanceNote::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
