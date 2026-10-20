<?php

namespace App\Domain\Skills\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8: immutable scale levels ({value, label, description, indicator}). Employee skills,
 * assessments, development needs and gaps pin the version they were measured on.
 */
#[Fillable(['tenant_id', 'skill_scale_id', 'version', 'levels', 'checksum', 'published_by', 'published_at'])]
class SkillScaleVersion extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        static::creating(fn (self $v) => $v->checksum = hash('sha256', (string) json_encode($v->levels)));
        static::updating(fn () => throw new \RuntimeException('A published skill scale version is immutable; publish a new version.'));
        static::deleting(fn () => throw new \RuntimeException('Skill scale versions are never deleted.'));
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'levels' => 'array', 'published_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'skills';
    }

    public function auditLabel(): string
    {
        return 'Skill scale version v'.$this->version;
    }

    public function scale(): BelongsTo
    {
        return $this->belongsTo(SkillScale::class, 'skill_scale_id');
    }

    /** @return list<float> */
    public function values(): array
    {
        return array_map(fn ($l) => (float) $l['value'], $this->levels ?? []);
    }

    public function has(float $value): bool
    {
        return in_array($value, $this->values(), true);
    }

    public function labelFor(?float $value): ?string
    {
        return $value === null ? null : (collect($this->levels)->first(fn ($l) => (float) $l['value'] === $value)['label'] ?? null);
    }

    public function min(): float
    {
        return min($this->values());
    }

    public function max(): float
    {
        return max($this->values());
    }
}
