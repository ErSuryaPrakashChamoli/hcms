<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'employee_id', 'date', 'type', 'requested_in', 'requested_out', 'reason', 'status', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note'])]
class AttendanceRegularisation extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    protected function casts(): array
    {
        return ['date' => 'date', 'requested_in' => 'datetime', 'requested_out' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return config("peopleos.attendance.regularisation_types.{$this->type}", $this->type).' on '.$this->date?->toDateString();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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
