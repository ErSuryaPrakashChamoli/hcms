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
 * Part L annual TDS ledger: one row per employee per finalized payroll run, read from payroll and
 * never recomputed. A row is never edited; when payroll changes, the row is superseded (or
 * withdrawn) and a new row is appended. `active_source_key` enforces one active row per source.
 */
#[Fillable(['tenant_id', 'legal_entity_id', 'employee_id', 'financial_year', 'month', 'quarter', 'payroll_run_id', 'payroll_entry_id', 'source_key', 'active_source_key', 'gross', 'taxable_earnings', 'tds_deducted', 'tax_regime', 'pan_available', 'compliance_rule_id', 'rule_version', 'rule_checksum', 'basis', 'status', 'superseded_by_id', 'created_at'])]
class TdsAnnualLedger extends Model
{
    use BelongsToTenant, ScopedByEmployee;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (TdsAnnualLedger $row) {
            if (array_diff(array_keys($row->getDirty()), ['status', 'superseded_by_id', 'active_source_key', 'payroll_entry_id']) !== [] || $row->getOriginal('status') !== 'active') {
                throw new RuntimeException('TDS ledger rows are immutable; payroll changes append a superseding row.');
            }
        });
        static::deleting(fn () => throw new RuntimeException('TDS ledger rows are never deleted.'));
    }

    protected function casts(): array
    {
        return ['month' => 'date', 'basis' => 'array', 'pan_available' => 'boolean', 'gross' => 'decimal:2', 'taxable_earnings' => 'decimal:2', 'tds_deducted' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
