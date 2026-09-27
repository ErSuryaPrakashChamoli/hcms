<?php

namespace App\Domain\Leave\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'employee_id', 'leave_type_id', 'period_year', 'days', 'status', 'reason', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note'])]
class LeaveEncashment extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'paid' => 'Paid'];

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
}
