<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A month's salary held back (HROne parity PY-06): payroll still calculates it and issues the payslip, but the
 * employee is left out of that month's bank file until the hold is released (then paid with the file, or outside it
 * with a payment reference).
 */
#[Fillable(['tenant_id', 'employee_id', 'pay_month', 'reason', 'status', 'held_by', 'released_at', 'released_by', 'release_note', 'release_reference'])]
class SalaryHold extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['held' => 'Held', 'released' => 'Released'];

    protected $attributes = ['status' => 'held'];

    protected function casts(): array
    {
        return ['released_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return "Salary hold {$this->pay_month}";
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
