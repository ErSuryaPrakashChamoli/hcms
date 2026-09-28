<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Phase 7: one published version of a review template — sections, the rating scale and competencies
 * as they were (snapshots), the workflow stages, weights and goal rules. Immutable: a change is a new
 * version. Cycles and appraisals pin the version they used, so history stays reproducible.
 */
#[Fillable(['tenant_id', 'performance_template_id', 'version', 'sections', 'rating_scale_id', 'rating_scale_snapshot', 'competency_snapshot', 'workflow', 'weights', 'goal_rules', 'checksum', 'status', 'published_by', 'published_at'])]
class PerformanceTemplateVersion extends Model
{
    use Auditable, BelongsToTenant;

    public const SECTIONS = ['goals', 'kpis', 'competencies', 'behavioural_competencies', 'achievements', 'challenges', 'development', 'manager_comments', 'employee_comments', 'ratings', 'overall_rating'];

    protected static function booted(): void
    {
        static::creating(function (self $v) {
            $v->checksum = hash('sha256', (string) json_encode([$v->sections, $v->rating_scale_snapshot, $v->competency_snapshot, $v->workflow, $v->weights, $v->goal_rules]));
        });
        static::updating(function (self $v) {
            if (array_diff(array_keys($v->getDirty()), ['status', 'updated_at']) !== []) {
                throw new RuntimeException('A published template version is immutable; publish a new version.');
            }
        });
        static::deleting(fn () => throw new RuntimeException('Template versions are never deleted.'));
    }

    protected function casts(): array
    {
        return ['sections' => 'array', 'rating_scale_snapshot' => 'array', 'competency_snapshot' => 'array', 'workflow' => 'array', 'weights' => 'array', 'goal_rules' => 'array', 'published_at' => 'datetime', 'version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return "Template version v{$this->version}";
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PerformanceTemplate::class, 'performance_template_id');
    }

    /** The rating scale exactly as pinned (an unsaved model built from the snapshot). */
    public function ratingScale(): RatingScale
    {
        return (new RatingScale)->forceFill(['name' => $this->rating_scale_snapshot['name'] ?? 'Pinned scale', 'code' => $this->rating_scale_snapshot['code'] ?? null, 'levels' => $this->rating_scale_snapshot['levels'] ?? []]);
    }

    public function requiredGoalWeight(): ?float
    {
        $value = $this->goal_rules['required_total_weight'] ?? null;

        return $value === null ? null : (float) $value;
    }
}
