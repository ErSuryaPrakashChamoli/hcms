<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An immutable snapshot generated at finalization (§31). Kept for as long as its run is finalized or paid, which is for good. */
#[Fillable(['tenant_id', 'payroll_entry_id', 'employee_id', 'number', 'snapshot', 'generated_at', 'viewed_at'])]
class Payslip extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected static function booted(): void
    {
        // Issued payslips are never withdrawn: finalized payroll is not reopened (decision C12-D1 open).
        static::deleting(function (self $payslip): void {
            if (in_array(PayrollRun::query()->whereKey(PayrollEntry::query()->whereKey($payslip->payroll_entry_id)->value('payroll_run_id'))->value('status'), ['finalized', 'paid'], true)) {
                throw new \RuntimeException('A payslip of finalized payroll is kept as issued and cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'generated_at' => 'datetime', 'viewed_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return "Payslip {$this->number}";
    }

    public function auditSensitiveAttributes(): array
    {
        return ['snapshot'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class, 'payroll_entry_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function get(string $path, mixed $default = null): mixed
    {
        return data_get($this->snapshot, $path, $default);
    }
}
