<?php

namespace App\Domain\Career\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Organisation\Models\Designation;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9 internal-mobility foundation: an employee's interest in a position, job family,
 * department, location or track. Not an application, not a requisition, never a recruitment record.
 */
#[Fillable(['tenant_id', 'employee_id', 'interest_type', 'designation_id', 'job_family_id', 'department_id', 'location_id', 'career_track_id', 'notes', 'status', 'effective_from', 'effective_to', 'created_by'])]
class MobilityInterest extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const TARGET_COLUMN = ['position' => 'designation_id', 'job_family' => 'job_family_id', 'department' => 'department_id', 'location' => 'location_id', 'career_track' => 'career_track_id'];

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(function (self $m) {
            $column = self::TARGET_COLUMN[$m->interest_type] ?? throw new \RuntimeException("Unknown mobility interest type '{$m->interest_type}'.");
            if (! $m->{$column}) {
                throw new \RuntimeException('A mobility interest names what the employee is interested in.');
            }
        });
        static::updating(function (self $m) {
            if ($m->getRawOriginal('status') !== 'active' || array_diff(array_keys($m->getDirty()), ['status', 'effective_to', 'updated_at']) !== []) {
                throw new \RuntimeException('A mobility interest is withdrawn, never edited.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Mobility interests are withdrawn, never deleted.'));
    }

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date'];
    }

    public function auditModule(): string
    {
        return 'career';
    }

    public function auditLabel(): string
    {
        return 'Mobility interest ('.$this->interest_type.')';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }
}
