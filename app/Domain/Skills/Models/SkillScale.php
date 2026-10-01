<?php

namespace App\Domain\Skills\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Phase 8: a configurable skill proficiency scale; its levels live in immutable versions. */
#[Fillable(['tenant_id', 'code', 'name', 'description', 'status', 'current_version_id'])]
class SkillScale extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(fn (self $s) => $s->code = strtoupper(trim((string) $s->code)));
    }

    public function auditModule(): string
    {
        return 'skills';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SkillScaleVersion::class)->orderBy('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(SkillScaleVersion::class, 'current_version_id');
    }
}
