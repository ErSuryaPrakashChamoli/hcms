<?php

namespace App\Domain\Feedback\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Feedback asked of someone about an employee (HROne setup parity P13): an exit interview by the employee, the manager or
 * HR, the manager's confirmation feedback, the employee's probation self-review, an induction evaluation. It is answered
 * on the copy of the template's questions it was raised with, by the person (or a holder of the role) it names. The
 * answers are kept out of the audit trail's values. Read through FeedbackRequests::canRead, never directly.
 */
#[Fillable(['tenant_id', 'feedback_template_id', 'process', 'subject_type', 'subject_id', 'employee_id', 'respondent', 'respondent_user_id', 'respondent_role_id', 'share_with_next_level', 'title', 'questions', 'answers', 'status', 'due_on', 'submitted_by', 'submitted_at', 'cancel_reason'])]
class FeedbackRequest extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['pending' => 'Pending', 'submitted' => 'Submitted', 'cancelled' => 'Cancelled'];

    protected $attributes = ['status' => 'pending', 'share_with_next_level' => false];

    protected function casts(): array
    {
        return ['questions' => 'array', 'answers' => 'array', 'due_on' => 'date', 'submitted_at' => 'datetime', 'share_with_next_level' => 'boolean'];
    }

    public function auditModule(): string
    {
        return 'feedback';
    }

    public function auditLabel(): string
    {
        return $this->title;
    }

    public function auditSensitiveAttributes(): array
    {
        return ['answers'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(FeedbackTemplate::class, 'feedback_template_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function respondentUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondent_user_id');
    }

    public function respondentRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'respondent_role_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** The person it names, or a holder of the role it names. */
    public function isRespondent(User $user): bool
    {
        if ($this->respondent_user_id !== null) {
            return (int) $this->respondent_user_id === (int) $user->id;
        }

        return $this->respondent_role_id !== null && $user->roles()->where('roles.id', $this->respondent_role_id)->exists();
    }

    public function processLabel(): string
    {
        return (string) config("peopleos.feedback.processes.{$this->process}.label", $this->process);
    }
}
