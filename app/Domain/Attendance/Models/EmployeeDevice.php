<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phone or browser an employee checks in from (HP-1 device binding). Under a check-in policy with
 * `single_device`, only an approved device of the employee may record punches, and one device cannot
 * serve two employees.
 */
#[Fillable(['tenant_id', 'employee_id', 'device_identifier', 'platform', 'label', 'status', 'first_seen_at', 'last_seen_at', 'reviewed_by', 'reviewed_at'])]
class EmployeeDevice extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $table = 'attendance_employee_devices';

    public const STATUSES = ['approved' => 'Approved', 'pending' => 'Pending approval', 'blocked' => 'Blocked'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return 'Device '.($this->label ?: substr((string) $this->device_identifier, 0, 12));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
