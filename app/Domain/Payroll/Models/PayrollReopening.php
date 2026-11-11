<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Streamline S2 (decision ST-1): one reopening of a closed payroll month — who reopened it and why, the totals and the
 * payslips it withdrew (kept here as issued), and what it gave back to its input sources. Written once, never changed.
 */
#[Fillable(['tenant_id', 'payroll_run_id', 'payroll_period_id', 'generation', 'previous_status', 'reason', 'reopened_by', 'summary', 'payslips', 'given_back'])]
class PayrollReopening extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \RuntimeException('A payroll reopening is kept as it was recorded.');
        });
        static::deleting(function (): void {
            throw new \RuntimeException('A payroll reopening is kept as it was recorded.');
        });
    }

    protected function casts(): array
    {
        return ['generation' => 'integer', 'summary' => 'array', 'payslips' => 'array', 'given_back' => 'array'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return 'Payroll reopening #'.($this->getAttributes()['generation'] ?? '');
    }

    public function auditSensitiveAttributes(): array
    {
        return ['payslips', 'summary'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
