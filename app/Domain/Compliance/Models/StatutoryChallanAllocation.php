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
 * HROne parity PO-13 (matrix 25 §25.6.12 #13): one employee's share of a TDS challan — the TDS that finalized payroll
 * deducted from them in the challan's month (read from the TDS ledger) and the amount this challan deposits for it. Written
 * once when the challan is recorded and never changed (D-PO7: finalized TDS is never edited); a cancelled challan's
 * allocations no longer count as deposited. Audited through the challan's own CREATE event.
 */
#[Fillable(['tenant_id', 'statutory_challan_id', 'legal_entity_id', 'employee_id', 'tds_annual_ledger_id', 'period_month', 'tds_deducted', 'amount', 'created_at'])]
class StatutoryChallanAllocation extends Model
{
    use BelongsToTenant, ScopedByEmployee;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Challan allocations are never changed.'));
        static::deleting(fn () => throw new RuntimeException('Challan allocations are never deleted.'));
    }

    protected function casts(): array
    {
        return ['period_month' => 'date', 'tds_deducted' => 'decimal:2', 'amount' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function challan(): BelongsTo
    {
        return $this->belongsTo(StatutoryChallan::class, 'statutory_challan_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
