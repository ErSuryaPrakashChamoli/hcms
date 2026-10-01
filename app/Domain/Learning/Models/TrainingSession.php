<?php

namespace App\Domain\Learning\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A classroom or virtual delivery of a course (§37). */
#[Fillable(['tenant_id', 'course_id', 'course_version_id', 'learning_instructor_id', 'title', 'mode', 'trainer_id', 'trainer_name', 'starts_at', 'ends_at', 'venue', 'meeting_url', 'capacity', 'status'])]
class TrainingSession extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'scheduled'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'capacity' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'learning';
    }

    public function auditLabel(): string
    {
        return $this->title.' '.$this->starts_at?->toDateString();
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'trainer_id');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(TrainingSessionAttendee::class);
    }

    public function seatsLeft(): ?int
    {
        return $this->capacity === null ? null : max(0, $this->capacity - $this->attendees()->whereIn('status', ['registered', 'attended'])->count());
    }

    public function waitlistCount(): int
    {
        return $this->attendees()->where('status', 'waitlisted')->count();
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(LearningInstructor::class, 'learning_instructor_id');
    }
}
