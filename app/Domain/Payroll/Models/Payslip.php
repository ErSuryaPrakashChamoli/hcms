<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An immutable snapshot generated at finalization (§31). Deleted only when a run is reopened. */
#[Fillable(['tenant_id', 'payroll_entry_id', 'employee_id', 'number', 'snapshot', 'generated_at', 'viewed_at'])]
class Payslip extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

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
