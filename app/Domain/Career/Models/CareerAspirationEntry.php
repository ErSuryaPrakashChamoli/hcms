<?php

namespace App\Domain\Career\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Organisation\Models\Location;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 9: one effective-dated career aspiration (short / medium / long term). Never overwritten — a new entry supersedes it. */
#[Fillable(['tenant_id', 'employee_id', 'term', 'target_designation_id', 'target_job_family_id', 'target_location_id', 'career_track_id', 'aspiration', 'direction', 'effective_from', 'effective_to', 'status', 'created_by', 'updated_by'])]
class CareerAspirationEntry extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $attributes = ['status' => 'current'];

    protected static function booted(): void
    {
        static::saving(function (self $a) {
            if (! array_key_exists($a->term, config('peopleos.career.aspiration_terms'))) {
                throw new \RuntimeException("Unknown aspiration term '{$a->term}'.");
            }
        });
        static::updating(function (self $a) {
            if ($a->getRawOriginal('status') !== 'current' || array_diff(array_keys($a->getDirty()), ['status', 'effective_to', 'updated_by', 'updated_at']) !== []) {
                throw new \RuntimeException('Career aspirations are history: record a new aspiration instead of editing one.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Career aspirations are never deleted.'));
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
        return 'Career aspiration ('.$this->term.')';
    }

    public function auditSensitiveAttributes(): array
    {
        return ['aspiration', 'direction'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function targetDesignation(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'target_designation_id');
    }

    public function targetJobFamily(): BelongsTo
    {
        return $this->belongsTo(JobFamily::class, 'target_job_family_id');
    }

    public function targetLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'target_location_id');
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(CareerTrack::class, 'career_track_id');
    }
}
