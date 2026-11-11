<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Settlements\SettlementReversal;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An immutable snapshot generated at finalization (§31), shown to the employee once its run's payslips are published. */
#[Fillable(['tenant_id', 'payroll_entry_id', 'employee_id', 'number', 'snapshot', 'generated_at', 'viewed_at'])]
class Payslip extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected static function booted(): void
    {
        // An issued payslip is a snapshot: once its run is finalized only the employee's first view is recorded on it.
        // A reopening of the latest closed month (ST-1) withdraws the month's payslips, keeping them on the reopening.
        static::updating(function (self $payslip): void {
            if (! SettlementReversal::active() && array_diff(array_keys($payslip->getDirty()), ['viewed_at', 'updated_at']) !== [] && self::issued($payslip)) {
                throw new \RuntimeException('A payslip of finalized payroll is kept as issued and cannot be changed.');
            }
        });
        static::deleting(function (self $payslip): void {
            if (! SettlementReversal::active() && self::issued($payslip)) {
                throw new \RuntimeException('A payslip of finalized payroll is kept as issued and cannot be deleted.');
            }
        });
    }

    private static function issued(self $payslip): bool
    {
        return in_array(PayrollRun::query()->whereKey(PayrollEntry::query()->whereKey($payslip->payroll_entry_id)->value('payroll_run_id'))->value('status'), ['finalized', 'paid'], true);
    }

    /** Payslips employees may see: their run is closed and its payslips were published (ST-2). */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->whereHas('entry.run', fn ($q) => $q->whereIn('status', ['finalized', 'paid'])->whereNotNull('payslips_published_at'));
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
