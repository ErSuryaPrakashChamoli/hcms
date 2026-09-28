<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One compared value of a parallel run: an employee × component, or a statutory return total.
 * Statuses: matched, difference, missing_reference, missing_peopleos, resolved. Values never change
 * after reconciliation.
 */
#[Fillable(['tenant_id', 'parallel_payroll_run_id', 'level', 'employee_id', 'employee_code', 'scope', 'component', 'peopleos_value', 'reference_value', 'difference', 'status', 'reason', 'resolution', 'reviewed_by', 'reviewed_at'])]
class ParallelPayrollLine extends Model
{
    use BelongsToTenant, ScopedByEmployee;

    public const OPEN = ['difference', 'missing_reference', 'missing_peopleos'];

    protected static function booted(): void
    {
        $guard = function (ParallelPayrollLine $line) {
            if (ParallelPayrollRun::query()->withoutGlobalScopes()->whereKey($line->parallel_payroll_run_id)->value('status') === 'reconciled') {
                throw new RuntimeException('A reconciled parallel run is immutable.');
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return ['peopleos_value' => 'decimal:2', 'reference_value' => 'decimal:2', 'difference' => 'decimal:2', 'reviewed_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
