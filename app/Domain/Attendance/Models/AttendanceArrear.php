<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An arrear day (HP-1): a day already locked by a finalised payroll whose attendance would now be different
 * (a late regularisation, leave or override). The locked record is never rewritten; the difference in paid
 * days is kept here as a quantity for the next payroll (`delta_days` > 0 gives back, < 0 takes away).
 */
#[Fillable(['tenant_id', 'employee_id', 'date', 'status_before', 'status_after', 'paid_before', 'paid_after', 'delta_days', 'status', 'settled_period_id', 'settled_at', 'note'])]
class AttendanceArrear extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['pending' => 'Pending', 'settled' => 'Settled', 'void' => 'Void'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['date' => 'date', 'paid_before' => 'decimal:2', 'paid_after' => 'decimal:2', 'delta_days' => 'decimal:2', 'settled_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return 'Attendance arrear '.$this->date?->toDateString();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
