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
 * Part M: the immutable record of how one statutory output line was produced — payroll run and
 * entry, employee, establishment, legal entity, regime, rule version and checksum, inputs,
 * calculated values and output values. A revision gets a new snapshot that `supersedes` the old.
 */
#[Fillable(['tenant_id', 'statutory_return_id', 'entry_type', 'entry_id', 'payroll_run_id', 'payroll_entry_id', 'employee_id', 'establishment_id', 'legal_entity_id', 'tax_regime', 'compliance_rule_id', 'rule_version', 'rule_checksum', 'inputs', 'calculated', 'output', 'checksum', 'supersedes_id', 'reason', 'captured_at'])]
class StatutorySnapshot extends Model
{
    use BelongsToTenant, ScopedByEmployee;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (StatutorySnapshot $snapshot) {
            $snapshot->captured_at ??= now();
            $snapshot->checksum = hash('sha256', (string) json_encode([$snapshot->entry_type, $snapshot->entry_id, $snapshot->compliance_rule_id, $snapshot->rule_checksum, $snapshot->inputs, $snapshot->calculated, $snapshot->output]));
        });
        static::updating(fn () => throw new RuntimeException('Statutory snapshots are immutable.'));
        static::deleting(fn () => throw new RuntimeException('Statutory snapshots are immutable.'));
    }

    protected function casts(): array
    {
        return ['inputs' => 'array', 'calculated' => 'array', 'output' => 'array', 'captured_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function statutoryReturn(): BelongsTo
    {
        return $this->belongsTo(StatutoryReturn::class);
    }
}
