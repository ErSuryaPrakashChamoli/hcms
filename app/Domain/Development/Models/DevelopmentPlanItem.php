<?php

namespace App\Domain\Development\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\People\Models\Skill;
use App\Domain\Performance\Models\DevelopmentNeed;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 8: one step of a development plan: goal, skill gap, learning activity, milestone or review. */
#[Fillable(['tenant_id', 'development_plan_id', 'item_type', 'title', 'development_need_id', 'skill_id', 'current_level', 'target_level', 'course_id', 'learning_path_id', 'learning_enrolment_id', 'due_on', 'status', 'completed_at', 'notes', 'sort_order'])]
class DevelopmentPlanItem extends Model
{
    use Auditable, BelongsToTenant;

    public const TYPES = ['goal' => 'Goal', 'skill_gap' => 'Skill gap', 'learning' => 'Learning activity', 'milestone' => 'Milestone', 'review' => 'Review'];

    protected $attributes = ['status' => 'open'];

    protected static function booted(): void
    {
        $guard = function (self $item) {
            if (! array_key_exists($item->item_type, self::TYPES)) {
                throw new \RuntimeException("Unknown plan item type '{$item->item_type}'.");
            }
            $plan = DevelopmentPlan::query()->withoutGlobalScope(AccessScope::class)->find($item->development_plan_id);
            if ($plan?->isClosed()) {
                throw new \RuntimeException('A closed development plan is read-only history.');
            }
        };
        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return ['current_level' => 'decimal:2', 'target_level' => 'decimal:2', 'due_on' => 'date', 'completed_at' => 'datetime', 'sort_order' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'development';
    }

    public function auditLabel(): string
    {
        return 'Plan item: '.$this->title;
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(DevelopmentPlan::class, 'development_plan_id');
    }

    public function need(): BelongsTo
    {
        return $this->belongsTo(DevelopmentNeed::class, 'development_need_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function path(): BelongsTo
    {
        return $this->belongsTo(LearningPath::class, 'learning_path_id');
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(LearningEnrolment::class, 'learning_enrolment_id');
    }
}
