<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Continuous feedback (§34): praise, constructive notes and feedback requests with replies.
 * Phase 7: anonymous feedback keeps its author for moderation, but the author is never serialized
 * and is shown only through Feedback::authorFor() (performance.anonymous_identity, audited).
 */
#[Fillable(['tenant_id', 'employee_id', 'author_id', 'type', 'visibility', 'is_anonymous', 'message', 'goal_id', 'competency_id', 'requested_from_id', 'parent_id', 'status'])]
class FeedbackEntry extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected static function booted(): void
    {
        static::retrieved(function (self $entry) {
            if (! empty($entry->getAttributes()['is_anonymous'])) {
                $entry->makeHidden(['author_id', 'author']);
            }
        });
        static::updating(function (self $entry) {
            if ($entry->isDirty(['author_id', 'is_anonymous', 'employee_id'])) {
                throw new \RuntimeException('The author, recipient and anonymity of feedback cannot change.');
            }
        });
    }

    protected function casts(): array
    {
        return ['is_anonymous' => 'boolean'];
    }

    /** The author's display name as anyone without the reveal permission sees it. */
    public function authorLabel(): string
    {
        return $this->is_anonymous ? 'Anonymous' : ($this->author?->person?->full_name ?? '—');
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return ucfirst($this->type).' feedback';
    }

    public function auditSensitiveAttributes(): array
    {
        return ['message', 'author_id'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'author_id');
    }

    public function requestedFrom(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_from_id');
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
