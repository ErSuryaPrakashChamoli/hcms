<?php

namespace App\Domain\Organisation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Which establishment an employee works for, effective-dated (Phase 5 Part C). Write through
 * EstablishmentAssignments only. History is immutable: a row can only be closed once (effective_to
 * set from null); establishment, employee and effective_from never change and rows are never deleted.
 */
#[Fillable(['tenant_id', 'employee_id', 'company_id', 'establishment_id', 'effective_from', 'effective_to', 'assignment_reason', 'source', 'created_by', 'closed_by', 'closed_at', 'closure_reason'])]
class EmployeeEstablishmentAssignment extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates, ScopedByEmployee;

    public const SOURCES = ['manual', 'transfer', 'backfill', 'import', 'onboarding'];

    protected static function booted(): void
    {
        static::updating(function (EmployeeEstablishmentAssignment $assignment) {
            $dirty = array_keys($assignment->getDirty());
            $closable = ['effective_to', 'closed_by', 'closed_at', 'closure_reason', 'updated_at'];

            // Read the stored row: a stale in-memory copy must not reopen or re-close history.
            $storedEnd = static::query()->withoutGlobalScopes()->whereKey($assignment->getKey())->value('effective_to');

            if (array_diff($dirty, $closable) !== [] || $storedEnd !== null) {
                throw new RuntimeException('Establishment assignment history is immutable; record a new assignment instead.');
            }
        });

        static::deleting(fn () => throw new RuntimeException('Establishment assignments are never deleted; close them instead.'));
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'organisation';
    }

    public function auditLabel(): string
    {
        return 'Establishment assignment from '.$this->effective_from?->toDateString();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }
}
