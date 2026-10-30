<?php

namespace App\Domain\Experience\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Models\InboxDecision;
use App\Domain\Identity\Models\User;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Models\WorkflowTask;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * HROne parity RQ-8 (Inbox → "Insight": task completed N, on time %, delayed %; matrix 20 §20.2.1 #7): an approver's own
 * responsiveness over a period, from what they decided:
 * - the workflow steps they handled — approved, rejected, done, sent back or closed as not relevant (their own, or as an
 *   out-of-office delegate; a step a delegate decided counts for the delegate) — due at the step's due time;
 * - the decisions they took on domain requests in the Approval Center (the Approvals page, the drawer, the unified inbox),
 *   due at the time the item showed then (`inbox_decisions`).
 * On time = decided by the due time; delayed = after it; decisions without a due time count as decided only. Decisions
 * taken on a module's own screen are not counted. By default only the viewer's own; holders of an existing HR or audit
 * right (`task.view_all`, `audit.view`) may look at a colleague whose employee record is in their access scope.
 */
final class InboxInsight
{
    /** @var array<int, string> */
    public const PERIODS = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'];

    public const DEFAULT_DAYS = 30;

    public function mayViewOthers(User $viewer): bool
    {
        return $viewer->hasPermission('task.view_all') || $viewer->hasPermission('audit.view');
    }

    /** @return array<int, string> user id => name: the people whose insight the viewer may open, themself first */
    public function people(User $viewer): array
    {
        $people = [(int) $viewer->id => $viewer->name.' (you)'];
        if (! $this->mayViewOthers($viewer)) {
            return $people;
        }
        // The employee access scope applies to this read: only colleagues the viewer may already see.
        $userIds = Employee::query()->whereNotNull('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values();
        User::forCurrentTenant()->whereKey($userIds->all())->whereKeyNot($viewer->id)->orderBy('name')->limit(500)->get(['id', 'name'])
            ->each(function (User $user) use (&$people) {
                $people[(int) $user->id] = $user->name;
            });

        return $people;
    }

    /**
     * @return array{person: string, days: int, decided: int, timed: int, on_time: int, delayed: int, on_time_pct: ?int, delayed_pct: ?int}
     *
     * @throws AuthorizationException when the viewer may not see that person's insight
     */
    public function for(User $viewer, ?User $person = null, int $days = self::DEFAULT_DAYS): array
    {
        $person ??= $viewer;
        if ((int) $person->id !== (int) $viewer->id && ! array_key_exists((int) $person->id, $this->people($viewer))) {
            throw new AuthorizationException('You can see your own insight only.');
        }
        $days = array_key_exists($days, self::PERIODS) ? $days : self::DEFAULT_DAYS;
        $rows = $this->decisions($person, now()->subDays($days));
        $timed = $rows->filter(fn (array $r) => $r['due'] !== null);
        $onTime = $timed->filter(fn (array $r) => $r['at']->lte($r['due']))->count();
        $delayed = $timed->count() - $onTime;

        return [
            'person' => $person->name,
            'days' => $days,
            'decided' => $rows->count(),
            'timed' => $timed->count(),
            'on_time' => $onTime,
            'delayed' => $delayed,
            'on_time_pct' => $timed->isEmpty() ? null : (int) round($onTime * 100 / $timed->count()),
            'delayed_pct' => $timed->isEmpty() ? null : (int) round($delayed * 100 / $timed->count()),
        ];
    }

    /** @return Collection<int, array{due: ?CarbonInterface, at: CarbonInterface}> */
    private function decisions(User $person, CarbonInterface $since): Collection
    {
        $id = (int) $person->id;
        $steps = WorkflowTask::query()
            ->where(fn (Builder $q) => $q->where(fn (Builder $own) => $own->where('completed_by', $id)->whereNull('delegate_id'))->orWhere('delegate_id', $id))
            ->whereNotNull('completed_at')->where('completed_at', '>=', $since)
            ->where(fn (Builder $q) => $q->whereIn('status', [TaskStatus::Approved, TaskStatus::Rejected, TaskStatus::Completed, TaskStatus::SentBack])
                ->orWhere(fn (Builder $n) => $n->where('status', TaskStatus::Skipped)->where('decision', 'not_relevant')))
            ->get(['id', 'due_at', 'completed_at'])
            ->map(fn (WorkflowTask $t) => ['due' => $t->due_at, 'at' => $t->completed_at]);
        $requests = InboxDecision::query()->where('user_id', $id)->where('decided_at', '>=', $since)->get(['id', 'due_at', 'decided_at'])
            ->map(fn (InboxDecision $d) => ['due' => $d->due_at, 'at' => $d->decided_at]);

        return $steps->concat($requests)->values();
    }
}
