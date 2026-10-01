<?php

namespace App\Domain\Talent\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 9: immutable dimensions [{key, label, levels: [{value, label}]}] that talent assessments pin. */
#[Fillable(['tenant_id', 'talent_assessment_model_id', 'version', 'dimensions', 'checksum', 'published_by', 'published_at'])]
class TalentAssessmentModelVersion extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        static::creating(fn (self $v) => $v->checksum = hash('sha256', (string) json_encode($v->dimensions)));
        static::updating(fn () => throw new \RuntimeException('A published assessment model version is immutable; publish a new version.'));
        static::deleting(fn () => throw new \RuntimeException('Assessment model versions are never deleted.'));
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'dimensions' => 'array', 'published_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'talent';
    }

    public function auditLabel(): string
    {
        return 'Talent model version v'.$this->version;
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(TalentAssessmentModel::class, 'talent_assessment_model_id');
    }

    /** @return array<string, list<float>> dimension key => allowed values */
    public function allowed(): array
    {
        return collect($this->dimensions)->mapWithKeys(fn ($d) => [$d['key'] => array_map(fn ($l) => (float) $l['value'], $d['levels'] ?? [])])->all();
    }
}
