<?php

namespace App\Domain\Claims\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only entitlement ledger of a pay-on-claim reimbursement (HROne parity PY-02): openings and monthly accruals
 * add, paid claims and lapses take away; the balance is their sum. Each entry is keyed so it is written once.
 */
#[Fillable(['tenant_id', 'employee_id', 'salary_component_id', 'financial_year', 'month', 'kind', 'amount', 'entry_key', 'note', 'created_by'])]
class ReimbursementLedgerEntry extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const KINDS = ['opening' => 'Opening', 'accrual' => 'Accrued', 'paid' => 'Claimed and paid', 'lapse' => 'Lapsed', 'adjustment' => 'Adjustment'];

    protected function casts(): array
    {
        return ['financial_year' => 'integer', 'amount' => 'decimal:2'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return 'Reimbursement '.$this->kind.' '.($this->month ?? $this->financial_year);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }
}
