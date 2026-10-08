<?php

namespace App\Domain\Talent\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Career\Models\CareerTrack;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 9: the controlled talent profile. Confidential notes are encrypted, hidden and read only with talent.confidential (audited). No hidden "high potential" flag. */
#[Fillable(['tenant_id', 'employee_id', 'career_track_id', 'mobility', 'critical_role_interest', 'development_priorities', 'latest_review_outcome', 'confidential_notes', 'lock_version', 'updated_by'])]
class TalentProfile extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $hidden = ['confidential_notes'];

    protected static function booted(): void
    {
        static::saving(function (self $p) {
            if ($p->mobility !== null && ! array_key_exists($p->mobility, config('peopleos.talent.mobility_levels'))) {
                throw new \RuntimeException("Unknown mobility '{$p->mobility}'.");
            }
        });
        static::updating(function (self $p) {
            if (! $p->isDirty('lock_version')) {
                $p->lock_version = (int) $p->getRawOriginal('lock_version') + 1;
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Talent profiles are never deleted.'));
    }

    protected function casts(): array
    {
        return ['critical_role_interest' => 'boolean', 'confidential_notes' => 'encrypted', 'lock_version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'talent';
    }

    public function auditLabel(): string
    {
        return 'Talent profile';
    }

    public function auditSensitiveAttributes(): array
    {
        return ['confidential_notes', 'development_priorities', 'latest_review_outcome'];
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
