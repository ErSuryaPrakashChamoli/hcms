<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A statutory amount set by hand for one employee in one pay period (HROne parity PY-14): the statutory engine still
 * computes the line, then replaces its amount with this one and records both on the line's basis. Every override and
 * its cancellation is audited with the reason.
 */
#[Fillable(['tenant_id', 'employee_id', 'payroll_period_id', 'code', 'amount', 'reason', 'status', 'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason'])]
class StatutoryOverride extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    /** Line code => [name, type, classification, sort order] used when the override adds a line the engine did not. */
    public const CODES = [
        'PF_EE' => ['Provident fund (employee)', 'deduction', 'pf_employee', 500],
        'VPF' => ['Voluntary provident fund', 'deduction', 'other_deduction', 501],
        'ESI_EE' => ['ESI (employee)', 'deduction', 'esi_employee', 510],
        'PT' => ['Professional tax', 'deduction', 'pt', 520],
        'LWF_EE' => ['Labour welfare fund (employee)', 'deduction', 'lwf_employee', 530],
        'TDS' => ['Income tax (TDS)', 'deduction', 'tds', 540],
        'PF_ER' => ['Provident fund (employer)', 'employer_contribution', 'pf_employer', 700],
        'ESI_ER' => ['ESI (employer)', 'employer_contribution', 'esi_employer', 710],
        'LWF_ER' => ['Labour welfare fund (employer)', 'employer_contribution', 'lwf_employer', 720],
    ];

    public const STATUSES = ['active' => 'Active', 'cancelled' => 'Cancelled'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'cancelled_at' => 'datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return "Statutory override {$this->code}";
    }

    public function codeLabel(): string
    {
        return self::CODES[$this->code][0] ?? $this->code;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
