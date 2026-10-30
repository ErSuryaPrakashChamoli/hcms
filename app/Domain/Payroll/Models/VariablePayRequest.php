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

/** An incentive, bonus or other variable payment requested for a pay month (HROne parity PY-05). */
#[Fillable(['tenant_id', 'employee_id', 'salary_component_id', 'variable_pay_plan_id', 'plan_month', 'amount', 'pay_month', 'reason', 'status', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note', 'payroll_run_id'])]
class VariablePayRequest extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'reviewed_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return "Variable pay {$this->pay_month}";
    }

    public function auditSensitiveAttributes(): array
    {
        return ['amount'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }

    /** HROne setup parity BP-9: the variable pay plan this payout was entered for (null = asked for ad hoc). */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(VariablePayPlan::class, 'variable_pay_plan_id');
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
