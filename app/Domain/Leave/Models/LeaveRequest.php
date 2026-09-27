<?php

namespace App\Domain\Leave\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'employee_id', 'leave_type_id', 'from_date', 'to_date', 'from_session', 'to_session', 'days', 'dates', 'reason', 'status', 'document_id', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note', 'cancelled_at', 'contact_details', 'cancel_requested_at', 'cancel_reason', 'cancellation_reviewed_by', 'cancellation_reviewed_at', 'idempotency_key'])]
class LeaveRequest extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    public const SESSIONS = ['full' => 'Full day', 'first_half' => 'First half', 'second_half' => 'Second half'];

    protected function casts(): array
    {
        return [
            'cancel_requested_at' => 'datetime',
            'cancellation_reviewed_at' => 'datetime',
            'from_date' => 'date',
            'to_date' => 'date',
            'days' => 'decimal:2',
            'dates' => 'array',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'leave';
    }

    public function auditLabel(): string
    {
        $type = $this->relationLoaded('leaveType') ? $this->leaveType : $this->leaveType()->first();

        return sprintf('%s %s–%s (%s d)', $type?->code ?? 'Leave', $this->from_date?->toDateString(), $this->to_date?->toDateString(), $this->days);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'document_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Session for a given date inside the span ('full', 'first_half', 'second_half'). */
    public function sessionOn(string $date): ?string
    {
        foreach ($this->dates ?? [] as $entry) {
            if (($entry['date'] ?? null) === $date) {
                return $entry['session'] ?? 'full';
            }
        }

        return null;
    }

    /** Statuses that hold the dates (block overlaps) — cancel_requested is still approved leave until decided. */
    public const ACTIVE = ['pending', 'approved', 'cancel_requested'];

    /** Statuses whose days count as taken leave. */
    public const TAKEN = ['approved', 'cancel_requested'];

    public function isOpen(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }
}
