<?php

namespace App\Domain\Workforce\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Workforce\Concerns\ScopedByOrganisationDimensions;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 10: a workforce budget for a scope and period, in one currency and on one declared cost
 * basis. Planning only — no accounting, no compensation, no payroll write. Draft is editable;
 * approved is locked and can only be superseded. Visible with workforce.costs.
 */
#[Fillable(['tenant_id', 'workforce_plan_version_id', 'company_id', 'organisation_node_id', 'business_unit_id', 'division_id', 'department_id', 'team_id', 'location_id', 'cost_centre_id', 'name', 'period_start', 'period_end', 'currency', 'cost_basis', 'amount', 'status', 'approved_by', 'approved_at', 'notes', 'created_by', 'lock_version'])]
class WorkforceBudget extends Model
{
    use Auditable, BelongsToTenant, ScopedByOrganisationDimensions;

    public const TRANSITIONS = ['draft' => ['approved'], 'approved' => ['superseded'], 'superseded' => []];

    protected $attributes = ['status' => 'draft'];

    protected static function booted(): void
    {
        static::saving(function (self $b) {
            if (! array_key_exists($b->cost_basis, config('peopleos.workforce.cost_bases'))) {
                throw new \RuntimeException("Unknown cost basis '{$b->cost_basis}'.");
            }
            if ((float) $b->amount < 0) {
                throw new \RuntimeException('A budget amount cannot be negative.');
            }
            if ($b->period_end !== null && $b->period_start !== null && $b->period_end->lt($b->period_start)) {
                throw new \RuntimeException('The budget period ends before it starts.');
            }
        });
        static::updating(function (self $b) {
            $from = $b->getRawOriginal('status');
            if ($b->isDirty('status') && ! in_array($b->status, self::TRANSITIONS[$from] ?? [], true)) {
                throw new \RuntimeException("A budget cannot move from {$from} to {$b->status}.");
            }
            if ($from !== 'draft' && array_diff(array_keys($b->getDirty()), ['status', 'approved_by', 'approved_at', 'lock_version', 'updated_at']) !== []) {
                throw new \RuntimeException('An approved budget is locked; record a new budget to change it.');
            }
            if (! $b->isDirty('lock_version')) {
                $b->lock_version = (int) $b->getRawOriginal('lock_version') + 1;
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Budgets are superseded, never deleted.'));
    }

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'amount' => 'decimal:2', 'approved_at' => 'datetime', 'lock_version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'workforce';
    }

    /** Amounts are masked in the audit trail (field security). */
    public function auditSensitiveAttributes(): array
    {
        return ['amount'];
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(WorkforcePlanVersion::class, 'workforce_plan_version_id');
    }
}
