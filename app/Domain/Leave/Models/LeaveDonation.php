<?php

namespace App\Domain\Leave\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Leave given by one employee (employee_id, the donor) to a colleague (HP-1 leave donation). On approval the
 * donor's balance is debited and the recipient's credited through two ledger adjustments.
 */
#[Fillable(['tenant_id', 'employee_id', 'recipient_employee_id', 'leave_type_id', 'period_year', 'days', 'reason', 'status', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note'])]
class LeaveDonation extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['days' => 'decimal:2', 'period_year' => 'integer', 'reviewed_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'leave';
    }

    public function auditLabel(): string
    {
        return "Leave donation {$this->days} d ({$this->period_year})";
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recipient_employee_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
