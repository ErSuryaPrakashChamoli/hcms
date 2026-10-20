<?php

namespace App\Domain\Career\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9: an employee's career profile — preferences, mobility, target roles and development
 * priorities — with sharing flags the employee controls for managers. Optimistic lock_version.
 */
#[Fillable(['tenant_id', 'employee_id', 'career_track_id', 'preferred_job_family_ids', 'preferred_location_ids', 'target_designation_ids', 'mobility', 'development_priorities', 'share_aspirations_with_manager', 'share_goals_with_manager', 'share_mobility_with_manager', 'lock_version', 'updated_by'])]
class CareerProfile extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected static function booted(): void
    {
        static::updating(function (self $p) {
            if (! $p->isDirty('lock_version')) {
                $p->lock_version = (int) $p->getRawOriginal('lock_version') + 1;
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Career profiles are never deleted.'));
    }

    protected function casts(): array
    {
        return ['preferred_job_family_ids' => 'array', 'preferred_location_ids' => 'array', 'target_designation_ids' => 'array', 'mobility' => 'array', 'share_aspirations_with_manager' => 'boolean', 'share_goals_with_manager' => 'boolean', 'share_mobility_with_manager' => 'boolean', 'lock_version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'career';
    }

    public function auditLabel(): string
    {
        return 'Career profile';
    }

    public function auditSensitiveAttributes(): array
    {
        return ['mobility', 'development_priorities', 'target_designation_ids'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(CareerTrack::class, 'career_track_id');
    }
}
