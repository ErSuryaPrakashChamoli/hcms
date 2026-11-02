<?php

namespace App\Domain\Leave\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Workflow\Models\WorkflowTask;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity RQ-4 (matrix 20 §20.1.18 Leave #8, §20.4.2 #6): private leave.
 *
 * Whether a request is private: the leave type's "Privacy of requests" in the employee's leave policy (TO-7 entitlement
 * row `privacy`) decides when it says "Always public" or "Always private"; when it says "Employee decides" — or nothing,
 * which is every policy until someone sets it — the employee answers "Make this leave visible to colleagues?" on the
 * apply form, and no answer means visible (the behaviour before RQ-4).
 *
 * Who sees a private leave in full (type, and anything else a screen shows about it): the employee, the people who may
 * view it (leave.view inside their access scope — HR, and managers holding it), the people who may approve it (leave.approve
 * inside their scope), whoever decided it, and the assignees of its workflow tasks. Everybody else sees "Away", with the
 * dates. A request that is not private is shown exactly as before. Screens never show a leave reason to colleagues.
 */
final class LeavePrivacy
{
    public const AWAY = 'Away';

    /** Policy values of the entitlement row's `privacy` (config: leave policy fields). */
    public const MODES = ['public' => 'Always public', 'employee' => 'Employee decides', 'private' => 'Always private'];

    public function __construct(private readonly LeaveEntitlements $entitlements) {}

    /** The leave type's rule for this employee: 'public', 'private', or 'employee' (also when the policy says nothing). */
    public function mode(Employee $employee, LeaveType $type, CarbonInterface|string|null $on = null): string
    {
        return self::modeOf($this->entitlements->forType($employee, $type, $on));
    }

    /** @param  array<string, mixed>|null  $rule  the leave type's entitlement row */
    public static function modeOf(?array $rule): string
    {
        $mode = $rule['privacy'] ?? null;

        return in_array($mode, ['public', 'private'], true) ? $mode : 'employee';
    }

    /**
     * Whether a new request is private: the policy's rule when it is fixed, else the employee's answer (none = visible).
     *
     * @param  array<string, mixed>|null  $rule
     */
    public static function decide(?array $rule, ?bool $private): bool
    {
        return match (self::modeOf($rule)) {
            'private' => true,
            'public' => false,
            default => $private === true,
        };
    }

    /** Whether $viewer may see the request's details (its type); always true for a request that is not private. */
    public function showsDetails(LeaveRequest $request, ?User $viewer): bool
    {
        if (! $request->isPrivate()) {
            return true;
        }
        if ($viewer === null) {
            return false;
        }
        // The employee and HR (view: leave.view in scope, or one's own), the approvers (leave.approve in scope).
        if ($viewer->can('view', $request) || $viewer->can('approve', $request)) {
            return true;
        }
        $attributes = $request->getAttributes();
        $id = (int) $viewer->getKey();
        if ($id === (int) ($attributes['reviewed_by'] ?? 0) || $id === (int) ($attributes['cancellation_reviewed_by'] ?? 0)) {
            return true;
        }

        return $this->taskIds($viewer, [(int) $request->getKey()]) !== [];
    }

    /** The leave type's name as $viewer may see it: "Away" for a private leave they may not see in full. */
    public function typeLabel(LeaveRequest $request, ?User $viewer, string $fallback = 'Leave'): string
    {
        return $this->showsDetails($request, $viewer) ? ($request->leaveType?->name ?? $fallback) : self::AWAY;
    }

    /**
     * Narrow $query — already limited to $viewer's access scope, as every LeaveRequest query is — to the requests whose
     * details $viewer may see, so that a filter on the leave type never finds a private leave through its type. Holders of
     * leave.view or leave.approve see every request in their scope in full (showsDetails() agrees), so nothing is narrowed.
     *
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    public function restrictToDetails(Builder $query, ?User $viewer): Builder
    {
        if ($viewer !== null && ($viewer->hasPermission('leave.view') || $viewer->hasPermission('leave.approve'))) {
            return $query;
        }
        if ($viewer === null) {
            return $query->where('leave_requests.is_private', false);
        }
        $own = Employee::query()->withoutGlobalScope(AccessScope::class)->where('user_id', $viewer->getKey())->pluck('id')->all();
        $tasks = $this->taskIds($viewer);

        return $query->where(fn (Builder $q) => $q->where('leave_requests.is_private', false)
            ->orWhereIn('leave_requests.employee_id', $own)
            ->orWhere('leave_requests.reviewed_by', $viewer->getKey())
            ->orWhere('leave_requests.cancellation_reviewed_by', $viewer->getKey())
            ->when($tasks !== [], fn (Builder $q) => $q->orWhereIn('leave_requests.id', $tasks)));
    }

    /**
     * Leave requests (of $ids, or any) whose workflow gave $viewer a task — assigned, delegated or completed by them.
     *
     * @param  list<int>|null  $ids
     * @return list<int>
     */
    private function taskIds(User $viewer, ?array $ids = null): array
    {
        $id = $viewer->getKey();

        return WorkflowTask::query()
            ->where(fn (Builder $q) => $q->where('assignee_id', $id)->orWhere('delegate_id', $id)->orWhere('completed_by', $id))
            ->whereHas('instance', fn (Builder $q) => $q->where('subject_type', (new LeaveRequest)->getMorphClass())
                ->when($ids !== null, fn (Builder $q) => $q->whereIn('subject_id', $ids)))
            ->with('instance:id,subject_id')->get(['id', 'workflow_instance_id'])
            ->map(fn (WorkflowTask $t) => (int) $t->instance?->subject_id)->filter()->unique()->values()->all();
    }
}
