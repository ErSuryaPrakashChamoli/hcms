<?php

namespace App\Domain\Learning\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A course (§37): modules, optional assessment, mandatory flag, certification validity. draft → published → retired. */
#[Fillable(['tenant_id', 'title', 'code', 'type', 'category', 'topic', 'difficulty', 'delivery_mode', 'language', 'description', 'duration_minutes', 'content_url', 'is_mandatory', 'validity_months', 'passing_score', 'attempts_allowed', 'owner_id', 'learning_provider_id', 'learning_instructor_id', 'cost', 'currency', 'prerequisite_course_ids', 'skill_outcomes', 'allow_self_enrol', 'requires_approval', 'approval_workflow_key', 'status', 'effective_from', 'effective_to', 'current_version_id', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'lock_version'])]
class Course extends Model
{
    use Auditable, BelongsToTenant;

    /** Phase 8 lifecycle. Published, scheduled and active courses are enrollable once effective. */
    public const STATUSES = ['draft' => 'Draft', 'pending_approval' => 'Pending approval', 'approved' => 'Approved', 'published' => 'Published', 'scheduled' => 'Scheduled', 'active' => 'Active', 'retired' => 'Retired', 'archived' => 'Archived'];

    public const TRANSITIONS = [
        'draft' => ['pending_approval', 'published', 'scheduled'],
        'pending_approval' => ['approved', 'draft'],
        'approved' => ['published', 'scheduled', 'draft'],
        'scheduled' => ['published', 'retired'],
        'published' => ['active', 'retired', 'draft', 'pending_approval'],
        'active' => ['retired', 'draft', 'pending_approval'],
        'retired' => ['archived', 'published'],
        'archived' => [],
    ];

    public const ENROLLABLE = ['published', 'active'];

    protected $attributes = ['status' => 'draft'];

    protected static function booted(): void
    {
        static::saving(function (self $c) {
            $c->code = strtoupper(trim((string) $c->code));
            if ($c->cost !== null && (float) $c->cost < 0) {
                throw new \RuntimeException('A course cost cannot be negative.');
            }
            if ($c->effective_from && $c->effective_to && $c->effective_to->lt($c->effective_from)) {
                throw new \RuntimeException('A course cannot stop being effective before it starts.');
            }
            if (in_array($c->id, array_map('intval', $c->prerequisite_course_ids ?? []), true)) {
                throw new \RuntimeException('A course cannot be its own prerequisite.');
            }
        });
        static::updating(function (self $c) {
            $from = $c->getRawOriginal('status');
            if ($c->isDirty('status') && ! in_array($c->status, self::TRANSITIONS[$from] ?? [], true)) {
                throw new \RuntimeException("A course cannot move from {$from} to {$c->status}.");
            }
            if ($from === 'archived') {
                throw new \RuntimeException('An archived course is read-only.');
            }
            if (! $c->isDirty('lock_version')) {
                $c->lock_version = (int) $c->getRawOriginal('lock_version') + 1;
            }
        });
        static::deleting(function (self $c) {
            if (CourseVersion::query()->where('course_id', $c->id)->exists()) {
                throw new \RuntimeException('A course with published versions is retired, never deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return ['duration_minutes' => 'integer', 'is_mandatory' => 'boolean', 'validity_months' => 'integer', 'passing_score' => 'integer', 'attempts_allowed' => 'integer', 'cost' => 'decimal:2', 'prerequisite_course_ids' => 'array', 'skill_outcomes' => 'array', 'allow_self_enrol' => 'boolean', 'requires_approval' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'lock_version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'learning';
    }

    public function auditLabel(): string
    {
        return "{$this->title} ({$this->code})";
    }

    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class)->orderBy('sort_order');
    }

    public function assessment(): HasOne
    {
        return $this->hasOne(Assessment::class);
    }

    /** Same row as assessment(); exposed as a has-many so the admin relation manager can edit it. */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(LearningEnrolment::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CourseVersion::class)->orderBy('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(CourseVersion::class, 'current_version_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LearningProvider::class, 'learning_provider_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(LearningInstructor::class, 'learning_instructor_id');
    }

    /** Enrollable: published or active, and inside its effective window. */
    public function isPublished(?CarbonInterface $on = null): bool
    {
        $on ??= now();

        return in_array($this->status, self::ENROLLABLE, true)
            && ($this->effective_from === null || $this->effective_from->lte($on))
            && ($this->effective_to === null || $this->effective_to->gte($on->copy()->startOfDay()));
    }

    public function isInstructorLed(): bool
    {
        return in_array($this->type, ['classroom', 'virtual'], true);
    }
}
