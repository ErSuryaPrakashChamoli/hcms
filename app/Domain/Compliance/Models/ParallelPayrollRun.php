<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Concerns\ScopedByOrganisation;
use App\Domain\Payroll\Models\PayrollRun;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Phase 6.5: one controlled parallel cycle for a finalized payroll run against the business's reference values. */
#[Fillable(['tenant_id', 'company_id', 'payroll_run_id', 'reference_source', 'reference_description', 'reference_file_sha256', 'status', 'imported_by', 'imported_at', 'compared_at', 'reconciled_by', 'reconciled_at', 'summary'])]
class ParallelPayrollRun extends Model
{
    use Auditable, BelongsToTenant, ScopedByOrganisation;

    public string $accessScopeDimension = 'parallel_payroll_run';

    protected function casts(): array
    {
        return ['summary' => 'array', 'imported_at' => 'datetime', 'compared_at' => 'datetime', 'reconciled_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'compliance';
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ParallelPayrollLine::class)->orderBy('level')->orderBy('employee_code')->orderBy('component');
    }
}
