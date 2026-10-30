<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne setup parity HP-8h (PF-5): an employee assessed in a 360 round, and their nomination set — who nominates (the
 * employee, the reporting manager or a role's holders), its state (to nominate → waiting for approval → approved, or sent
 * back) and the decision. HROne's "360 nomination" request: approved as one set.
 */
#[Fillable(['tenant_id', 'feedback_round_id', 'employee_id', 'nominator', 'nominator_user_id', 'nominator_role_id', 'nomination_status', 'submitted_at', 'submitted_by', 'decided_by', 'decided_at', 'decision_note'])]
class FeedbackParticipant extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $attributes = ['nomination_status' => 'draft', 'nominator' => 'employee'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \RuntimeException('360 feedback participants are never deleted.'));
    }

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return '360 feedback nominations #'.$this->id;
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(FeedbackRound::class, 'feedback_round_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function nominatorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nominator_user_id');
    }

    public function nominatorRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'nominator_role_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function nominations(): HasMany
    {
        return $this->hasMany(FeedbackNomination::class);
    }

    /** The person (or a holder of the role) who nominates reviewers. */
    public function isNominator(User $user): bool
    {
        if ($this->nominator_user_id !== null) {
            return (int) $this->nominator_user_id === (int) $user->id;
        }

        return $this->nominator_role_id !== null && $user->roles()->where('roles.id', $this->nominator_role_id)->exists();
    }

    public function statusLabel(): string
    {
        return (string) config("peopleos.performance.feedback_360.participant_statuses.{$this->nomination_status}", $this->nomination_status);
    }
}
