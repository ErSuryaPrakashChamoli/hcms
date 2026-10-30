<?php

namespace App\Domain\Expense\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Owner decision 2026-10-29 (EX-F): the legs of one workflow group inside a trip that spans several groups
 * (expense.travel.workflow.*). Each part is approved or rejected on its own — by a workflow listening to
 * expense.travel_requested (its context carries the part's workflow group), else in the Approval Center — showing only its
 * legs. The trip is approved when every part is, rejected when any part is; a cancelled trip cancels its open parts.
 */
#[Fillable(['tenant_id', 'travel_request_id', 'employee_id', 'workflow_group', 'label', 'status', 'decided_at', 'decided_by', 'decision_note'])]
class TravelRequestPart extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    public const STATUSES = ['pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function auditLabel(): string
    {
        return 'Travel request part #'.($this->getAttributes()['id'] ?? '').' ('.($this->getAttributes()['label'] ?? '').')';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TravelRequest::class, 'travel_request_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function legs(): HasMany
    {
        return $this->hasMany(TravelRequestLeg::class, 'travel_request_part_id')->orderBy('sequence')->orderBy('id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
