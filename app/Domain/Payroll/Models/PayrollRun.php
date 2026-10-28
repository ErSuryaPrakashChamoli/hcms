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

    protected static function booted(): void
    {
        // Finalized payroll is history (Phase 4 §32): its one remaining step is being marked paid. It is never reopened,
        // changed or deleted, whatever the caller. Pay mistakes go into an open month as arrears or recoveries; a
        // correction caused by a wrong statutory profile waits for decision C12-D1 (open).
        static::updating(function (self $run): void {
            $from = $run->getOriginal('status');
            $paying = $from === 'finalized' && $run->status === 'paid' && array_diff(array_keys($run->getDirty()), ['status', 'paid_at', 'updated_at']) === [];
            if (in_array($from, ['finalized', 'paid'], true) && ! $paying) {
                throw new \RuntimeException('Finalized payroll cannot be reopened or changed; correct the month with an arrear or a recovery in an open month.');
            }
        });
        static::deleting(function (self $run): void {
            if (in_array($run->getOriginal('status'), ['finalized', 'paid'], true)) {
                throw new \RuntimeException('Finalized payroll is kept as history and cannot be deleted.');
            }
        });
    }

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
