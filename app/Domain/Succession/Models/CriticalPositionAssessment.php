<?php

namespace App\Domain\Succession\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 9: an immutable criticality assessment recorded by a person (criticality, impact, scarcity, replacement difficulty, operational dependency). */
#[Fillable(['tenant_id', 'critical_position_id', 'criticality', 'business_impact', 'scarcity', 'replacement_difficulty', 'operational_dependency', 'reason', 'assessed_by', 'assessed_at', 'effective_from'])]
class CriticalPositionAssessment extends Model
{
    use Auditable, BelongsToTenant;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::saving(function (self $a) {
            if (! array_key_exists($a->criticality, config('peopleos.talent.criticality_levels'))) {
                throw new \RuntimeException("Unknown criticality '{$a->criticality}'.");
            }
            foreach (['business_impact', 'scarcity', 'replacement_difficulty', 'operational_dependency'] as $field) {
                if (! array_key_exists($a->{$field}, config('peopleos.talent.impact_levels'))) {
                    throw new \RuntimeException("Unknown {$field} '{$a->{$field}}'.");
                }
            }
            if (trim((string) $a->reason) === '') {
                throw new \RuntimeException('A criticality assessment needs a reason.');
            }
        });
        static::updating(fn () => throw new \RuntimeException('Criticality assessments are immutable; record a new one.'));
        static::deleting(fn () => throw new \RuntimeException('Criticality assessments are never deleted.'));
    }

    protected function casts(): array
    {
        return ['assessed_at' => 'datetime', 'effective_from' => 'date'];
    }

    public function auditModule(): string
    {
        return 'succession';
    }

    public function auditLabel(): string
    {
        return 'Criticality assessment #'.$this->id;
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(CriticalPosition::class, 'critical_position_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
