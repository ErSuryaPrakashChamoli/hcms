<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A one-off input for a period: extra earning, extra deduction, or manual LOP days (§31). */
#[Fillable(['tenant_id', 'employee_id', 'payroll_period_id', 'salary_component_id', 'type', 'name', 'amount', 'taxable', 'status', 'approved_by', 'approved_at', 'source_type', 'source_id', 'reference_period_id', 'note', 'created_by'])]
class PayrollAdjustment extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const TYPES = ['earning' => 'Additional earning', 'deduction' => 'Additional deduction', 'lop' => 'Loss of pay (days)', 'reimbursement' => 'Reimbursement (non-taxable)', 'arrear' => 'Arrear (earning for an earlier period)', 'recovery' => 'Recovery (deduction for an earlier period)', 'correction' => 'Correction'];

    protected $attributes = ['status' => 'approved'];

    protected static function booted(): void
    {
        static::creating(function (self $adjustment): void {
            if (app(SettingsRepository::class)->get('payroll.adjustments.require_approval', false) && $adjustment->status === 'approved' && $adjustment->approved_by === null) {
                $adjustment->status = 'pending';
            }
            if ($adjustment->status === 'approved' && $adjustment->approved_at === null) {
                $adjustment->approved_at = now();
            }
        });
        // Adjustments of a closed period are history.
        $guard = function (self $adjustment): void {
            if (PayrollPeriod::query()->whereKey($adjustment->payroll_period_id)->value('status') === 'closed') {
                throw new \RuntimeException('The payroll period is closed; record an arrear or recovery in an open period instead.');
            }
        };
        static::creating($guard);
        static::created(fn (self $adjustment) => app(AuditRecorder::class)->record(AuditAction::PayrollAdjusted, 'payroll', $adjustment, reason: $adjustment->note, metadata: ['employee_id' => $adjustment->employee_id, 'type' => $adjustment->type, 'status' => $adjustment->status]));
        static::updating($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'taxable' => 'boolean', 'approved_at' => 'datetime'];
    }

    /** Only approved adjustments enter a calculation (Phase 4 §26). */
    #[Scope]
    protected function approved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->type})";
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }
}
