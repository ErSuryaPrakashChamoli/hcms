<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne setup parity HP-8h (PF-2 … PF-4): someone asked for 360 feedback about an employee — the identity side only (who
 * was asked, from which source, and whether they answered). What they answered is a FeedbackResponse, linked back to the
 * nomination only when the source is not anonymous. A client is held by name and e-mail alone and answers through a
 * signed link whose token is stored hashed (token_hash) and cleared once used.
 */
#[Fillable(['tenant_id', 'feedback_round_id', 'feedback_participant_id', 'employee_id', 'relation', 'reviewer_employee_id', 'reviewer_user_id', 'external_name', 'external_email', 'is_anonymous', 'source', 'status', 'nominated_by', 'token_hash', 'token_expires_at', 'requested_at', 'responded_on'])]
class FeedbackNomination extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $attributes = ['status' => 'draft', 'source' => 'nominated', 'is_anonymous' => false];

    protected $hidden = ['token_hash'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \RuntimeException('360 feedback nominations are never deleted; cancel them instead.'));
    }

    protected function casts(): array
    {
        return ['is_anonymous' => 'boolean', 'token_expires_at' => 'datetime', 'requested_at' => 'datetime', 'responded_on' => 'date'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return '360 feedback nomination #'.$this->id;
    }

    /** The link token and the client's e-mail are never written to the audit trail in clear. */
    public function auditSensitiveAttributes(): array
    {
        return ['token_hash', 'external_email'];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(FeedbackRound::class, 'feedback_round_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(FeedbackParticipant::class, 'feedback_participant_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewer_employee_id');
    }

    public function reviewerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function isExternal(): bool
    {
        return $this->relation === 'client';
    }

    public function reviewerName(): string
    {
        if ($this->isExternal()) {
            return (string) $this->external_name;
        }
        $this->loadMissing('reviewer.person');

        return (string) ($this->reviewer?->person?->full_name ?? $this->reviewer?->employee_code ?? '—');
    }

    public function relationLabel(): string
    {
        return (string) config("peopleos.performance.feedback_360.relations.{$this->relation}", $this->relation);
    }

    public function statusLabel(): string
    {
        return (string) config("peopleos.performance.feedback_360.nomination_statuses.{$this->status}", $this->status);
    }
}
