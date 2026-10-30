<?php

namespace App\Domain\Expense\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * HROne setup parity EX-D: one movement of an expense advance (ledger): dispensed (+), adjusted against an expense report,
 * refunded by the employee, written off, recovered through payroll or the final settlement (EX-E; the run or settlement is
 * recorded) (−). Each entry has a key unique per advance, so it is posted once. Entries are never changed.
 */
#[Fillable(['tenant_id', 'expense_advance_id', 'employee_id', 'kind', 'amount', 'expense_report_id', 'payroll_run_id', 'final_settlement_id', 'entry_key', 'entry_date', 'reference', 'note', 'created_by'])]
class ExpenseAdvanceEntry extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    public const KINDS = ['disbursed' => 'Dispensed', 'adjusted' => 'Adjusted against a report', 'refunded' => 'Refunded', 'written_off' => 'Written off', 'recovered' => 'Recovered'];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Advance ledger entries are never changed: post a new entry instead.');
        });
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'entry_date' => 'date'];
    }

    public function auditModule(): string
    {
        return 'expense';
    }

    public function auditLabel(): string
    {
        return 'Advance ledger '.($this->getAttributes()['kind'] ?? '').' #'.($this->getAttributes()['expense_advance_id'] ?? '');
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(ExpenseAdvance::class, 'expense_advance_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ExpenseReport::class, 'expense_report_id');
    }
}
