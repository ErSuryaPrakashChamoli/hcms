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
 * A leave encashment: requested by the employee (or by HR for them, PO-8), or created already approved by an HR batch
 * (`leave_encashment_batch_id`). `pay_month` ('YYYY-MM', PO-8) names the month whose pay run pays it; null = the next one.
 */
#[Fillable(['tenant_id', 'employee_id', 'leave_type_id', 'period_year', 'days', 'status', 'reason', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'attachments', 'leave_encashment_batch_id', 'pay_month'])]
class LeaveEncashment extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];

    protected function casts(): array
    {
        return ['days' => 'decimal:2', 'period_year' => 'integer', 'reviewed_at' => 'datetime', 'cancelled_at' => 'datetime', 'attachments' => 'array'];
    }

    public function auditModule(): string
    {
        return 'leave';
    }

    public function auditLabel(): string
    {
        return "Encashment {$this->days} d ({$this->period_year})";
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** HROne parity PO-8: who raised it (the employee, or HR for the employee). */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** HROne parity PO-8: the HR batch it came from (null = a request). */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(LeaveEncashmentBatch::class, 'leave_encashment_batch_id');
    }
}
