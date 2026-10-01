<?php

namespace App\Domain\Talent\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 9: a configurable talent assessment model (dimensions live in immutable versions; a 9-box is one possible configuration). */
#[Fillable(['tenant_id', 'code', 'name', 'description', 'status', 'current_version_id'])]
class TalentAssessmentModel extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(fn (self $m) => $m->code = strtoupper(trim((string) $m->code)));
        static::deleting(fn () => throw new \RuntimeException('Assessment models are retired, never deleted (assessments pin their versions).'));
    }

    public function auditModule(): string
    {
        return 'talent';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(TalentAssessmentModelVersion::class, 'current_version_id');
    }
}
