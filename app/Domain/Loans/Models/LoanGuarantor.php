<?php

namespace App\Domain\Loans\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne setup parity BP-9: a colleague standing guarantor for a loan (the loan policy says how many are needed and for
 * how many open loans one employee may stand). When the policy asks for it, each guarantor accepts or declines before
 * the loan can be approved. `loans.guarantor_employee_id` keeps the first guarantor, as before.
 */
#[Fillable(['tenant_id', 'loan_id', 'employee_id', 'status', 'decided_at', 'note'])]
class LoanGuarantor extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['pending' => 'Waiting for the guarantor', 'accepted' => 'Accepted', 'declined' => 'Declined'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return 'Loan guarantor #'.$this->id;
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
