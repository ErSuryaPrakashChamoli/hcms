<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One pass through the payroll pipeline for a period (§31). */
#[Fillable(['tenant_id', 'company_id', 'payroll_period_id', 'status', 'calculation_version', 'totals', 'rule_versions', 'reconciliation', 'operation_id', 'exception_count', 'created_by', 'calculated_at', 'approved_by', 'approved_at', 'finalized_by', 'finalized_at', 'paid_at', 'notes'])]
class PayrollRun extends Model
{
    use Auditable, BelongsToTenant;

    public const EDITABLE = ['draft', 'calculated', 'validated'];

    protected function casts(): array
    {
        return [
            'rule_versions' => 'array',
            'reconciliation' => 'array',
            'totals' => 'array',
            'exception_count' => 'integer',
            'calculated_at' => 'datetime',
            'approved_at' => 'datetime',
            'finalized_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        $period = $this->relationLoaded('period') ? $this->period : $this->period()->first();

        return 'Payroll run '.($period?->label() ?? '#'.$this->id);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['finalized', 'paid'], true);
    }

    public function total(string $key): float
    {
        return (float) data_get($this->totals, $key, 0);
    }
}
