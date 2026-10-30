<?php

namespace App\Domain\Loans\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One instalment of a loan schedule, recovered by payroll in its month unless prepaid, deferred or waived. */
#[Fillable(['tenant_id', 'loan_id', 'employee_id', 'sequence', 'due_month', 'principal_part', 'interest_part', 'amount', 'status', 'payroll_run_id', 'recovered_on', 'note'])]
class LoanInstallment extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['due' => 'Due', 'recovered' => 'Recovered', 'prepaid' => 'Prepaid', 'deferred' => 'Deferred', 'waived' => 'Waived'];

    protected $attributes = ['status' => 'due', 'interest_part' => 0];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'principal_part' => 'decimal:2', 'interest_part' => 'decimal:2', 'amount' => 'decimal:2', 'recovered_on' => 'date'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return "Loan instalment {$this->sequence} ({$this->due_month})";
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
