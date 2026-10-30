<?php

namespace App\Domain\Loans\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An employee loan or salary advance (HROne parity PY-01) with its instalment schedule. */
#[Fillable(['tenant_id', 'employee_id', 'number', 'loan_type', 'principal', 'interest_rate', 'installments', 'emi', 'first_due_month', 'disbursed_through', 'status', 'reason', 'exceptions', 'requested_terms', 'guarantor_employee_id', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note', 'disbursed_on', 'outstanding', 'closed_at', 'cancel_reason'])]
class Loan extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['pending' => 'Pending approval', 'approved' => 'Approved, to disburse', 'active' => 'Recovering', 'closed' => 'Closed', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    protected $attributes = ['status' => 'pending', 'interest_rate' => 0, 'disbursed_through' => 'payroll'];

    protected function casts(): array
    {
        return ['principal' => 'decimal:2', 'interest_rate' => 'decimal:3', 'installments' => 'integer', 'emi' => 'decimal:2', 'outstanding' => 'decimal:2', 'reviewed_at' => 'datetime', 'disbursed_on' => 'date', 'closed_at' => 'datetime',
            'exceptions' => 'array', 'requested_terms' => 'array'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return "Loan {$this->number}";
    }

    public function auditSensitiveAttributes(): array
    {
        return ['principal', 'emi', 'outstanding'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function guarantor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'guarantor_employee_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** HROne setup parity BP-9: every guarantor (the first is also kept in guarantor_employee_id). */
    public function guarantors(): HasMany
    {
        return $this->hasMany(LoanGuarantor::class)->orderBy('id');
    }

    /** The policy limits the request is beyond, as one line for the approver (null when within the policy). */
    public function exceptionSummary(): ?string
    {
        $exceptions = array_key_exists('exceptions', $this->getAttributes()) ? (array) $this->exceptions : [];

        return $exceptions === [] ? null : implode(' ', array_map(fn ($e) => (string) ($e['message'] ?? ''), $exceptions));
    }

    public function schedule(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('sequence');
    }

    public function typeLabel(): string
    {
        return config("peopleos.loans.types.{$this->loan_type}", ucfirst(str_replace('_', ' ', (string) $this->loan_type)));
    }
}
