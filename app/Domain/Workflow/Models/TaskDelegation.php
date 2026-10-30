<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne setup parity P4 (Inbox → Delegate, "out of office"): a person (the delegator) hands their approvals of the listed
 * request types to another person (the delegate) from one date to another. While it runs, the delegate sees those
 * items in their Approval Center and task inbox and decides them in the delegator's stead (the decision carries the
 * delegator's authority; the audit records the delegate on behalf of the delegator). Statuses: active → revoked (ended
 * early); a delegation past its end date simply stops applying. Written only by TaskDelegations; every change audited.
 */
#[Fillable(['tenant_id', 'delegator_id', 'delegate_id', 'starts_on', 'ends_on', 'types', 'reason', 'status', 'created_by', 'revoked_by', 'revoked_at', 'revoke_reason'])]
class TaskDelegation extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['active' => 'Active', 'revoked' => 'Ended early'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'types' => 'array', 'revoked_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'workflow';
    }

    public function auditLabel(): string
    {
        return 'Out of office '.$this->starts_on?->format('d M Y').' – '.$this->ends_on?->format('d M Y');
    }

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    /** Active and covering the day (today by default). */
    public function runsOn(?CarbonInterface $day = null): bool
    {
        $day = ($day ?? now())->toDateString();

        return $this->status === 'active' && $this->starts_on !== null && $this->ends_on !== null
            && $this->starts_on->toDateString() <= $day && $this->ends_on->toDateString() >= $day;
    }

    /** What the delegation shows as: running, upcoming, over or ended early. */
    public function stateLabel(): string
    {
        $today = now()->toDateString();

        return match (true) {
            $this->status === 'revoked' => 'Ended early',
            $this->ends_on !== null && $this->ends_on->toDateString() < $today => 'Over',
            $this->starts_on !== null && $this->starts_on->toDateString() > $today => 'Upcoming',
            default => 'Running',
        };
    }
}
