<?php

namespace App\Domain\Learning\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A course (§37): modules, optional assessment, mandatory flag, certification validity. draft → published → retired. */
#[Fillable(['tenant_id', 'title', 'code', 'type', 'category', 'description', 'duration_minutes', 'content_url', 'is_mandatory', 'validity_months', 'passing_score', 'attempts_allowed', 'owner_id', 'status'])]
class Course extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['draft' => 'Draft', 'published' => 'Published', 'retired' => 'Retired'];

    protected $attributes = ['status' => 'draft'];

    protected static function booted(): void
    {
        static::saving(fn (self $c) => $c->code = strtoupper(trim((string) $c->code)));
    }

    protected function casts(): array
    {
        return ['duration_minutes' => 'integer', 'is_mandatory' => 'boolean', 'validity_months' => 'integer', 'passing_score' => 'integer', 'attempts_allowed' => 'integer'];
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

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isInstructorLed(): bool
    {
        return in_array($this->type, ['classroom', 'virtual'], true);
    }
}
