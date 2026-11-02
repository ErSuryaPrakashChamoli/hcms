<?php

namespace App\Domain\Claims\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Data import DI-4 (decision): a bill behind a reimbursement paid every month with salary (pay by default). From the
 * organisation's bills date, TDS treats what the reimbursement pays in the year beyond its bills as taxable salary
 * (ReimbursementBills). Only the status moves (cancelled with a reason); a bill is never edited or deleted.
 */
#[Fillable(['tenant_id', 'employee_id', 'salary_component_id', 'financial_year', 'bill_date', 'bill_number', 'vendor', 'amount', 'description', 'status', 'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason'])]
class ReimbursementBill extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['active' => 'Active', 'cancelled' => 'Cancelled'];

    /** What may change once a bill is on file. */
    private const MUTABLE = ['status', 'cancelled_by', 'cancelled_at', 'cancel_reason', 'updated_at'];

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::updating(function (self $bill): void {
            if (array_diff(array_keys($bill->getDirty()), self::MUTABLE) !== [] || $bill->getRawOriginal('status') !== 'active') {
                throw new RuntimeException('A reimbursement bill is never edited: cancel it and record the right one.');
            }
        });
        static::deleting(fn () => throw new RuntimeException('Reimbursement bills are cancelled, never deleted.'));
    }

    protected function casts(): array
    {
        return ['financial_year' => 'integer', 'bill_date' => 'date', 'amount' => 'decimal:2', 'cancelled_at' => 'datetime'];
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
        return 'Reimbursement bill '.($this->bill_number ?: $this->bill_date?->toDateString());
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
