<?php

namespace App\Domain\Claims\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reimbursement claim with its bills against a pay-on-claim component (HROne parity PY-02). */
#[Fillable(['tenant_id', 'employee_id', 'salary_component_id', 'financial_year', 'claim_date', 'amount', 'approved_amount', 'description', 'bills', 'attachments', 'status', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note', 'payroll_run_id'])]
class ReimbursementClaim extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['financial_year' => 'integer', 'claim_date' => 'date', 'amount' => 'decimal:2', 'approved_amount' => 'decimal:2', 'bills' => 'array', 'attachments' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return 'Reimbursement claim '.$this->claim_date?->toDateString();
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'attachments'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function payable(): float
    {
        return (float) ($this->approved_amount ?? $this->amount);
    }
}
