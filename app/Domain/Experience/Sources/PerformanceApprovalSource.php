<?php

namespace App\Domain\Experience\Sources;

use App\Domain\Experience\Contracts\ApprovalSource;
use App\Domain\Experience\Contracts\DescribesApprovalKinds;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Models\GoalPlan;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Services\GoalPlans;
use App\Domain\Performance\Services\ImprovementPlans;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Resources\ImprovementPlans\ImprovementPlanResource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HROne setup parity HP-8d / HP-8f decisions in the Approval Center: goal sheets submitted for approval (goal sheet
 * policy), improvement plans waiting for approval and PIP outcomes (evaluations) waiting for approval (PIP policy).
 * Records already running a workflow are decided as workflow tasks, so they are not listed twice. The domain services
 * check again when deciding (GoalPlans, ImprovementPlans).
 */
final class PerformanceApprovalSource implements ApprovalSource, DescribesApprovalKinds
{
    private const SCAN = 200;

    public function types(): array
    {
        return ['goal_plan', 'pip', 'pip_evaluation'];
    }

    /** HROne setup parity P3: request names for Setup → Inbox actions. */
    public function kindLabels(): array
    {
        return ['goal_plan' => 'Goal sheet approval', 'pip' => 'PIP request', 'pip_evaluation' => 'PIP evaluation request'];
    }

    public function pending(User $user, ?int $about = null): array
    {
        $items = [];
        if ($user->hasPermission('performance.team') || $user->hasPermission('performance.manage')) {
            $items = [...$items, ...$this->goalPlans($user, $about)];
        }
        if ($user->hasPermission('performance.pip') || $user->hasPermission('performance.manage')) {
            $items = [...$items, ...$this->plans($user, $about)];
        }

        return $items;
    }

    public function decide(ApprovalItem $item, User $user, string $decision, ?string $note): string
    {
        $record = $item->record;

        return match ($item->type) {
            'goal_plan' => $decision === 'approve'
                ? tap('Goal sheet approved', fn () => app(GoalPlans::class)->approve($record, $note, $user))
                : tap('Goal sheet sent back', fn () => app(GoalPlans::class)->reject($record, (string) $note, $user)),
            'pip' => $decision === 'approve'
                ? tap('Improvement plan approved and started', fn () => app(ImprovementPlans::class)->approve($record, $note, $user))
                : tap('Improvement plan rejected', fn () => app(ImprovementPlans::class)->reject($record, (string) $note, $user)),
            'pip_evaluation' => $decision === 'approve'
                ? tap('Outcome recorded', fn () => app(ImprovementPlans::class)->approveEvaluation($record, $note, $user))
                : tap('Outcome sent back', fn () => app(ImprovementPlans::class)->rejectEvaluation($record, (string) $note, $user)),
            default => throw new AuthorizationException('Unsupported item.'),
        };
    }

    /** @return list<ApprovalItem> */
    private function goalPlans(User $user, ?int $about): array
    {
        $rows = GoalPlan::query()->with(['employee.person', 'cycle', 'submitter'])->where('status', 'pending')
            ->when($about !== null, fn ($q) => $q->where('employee_id', $about))->orderBy('id')->limit(self::SCAN)->get();
        $rows = $this->withoutWorkflow($rows, GoalPlan::class);
        app(AccessScopes::class)->primeEmployeeIds($user, $rows->pluck('employee_id'));
        $plans = app(GoalPlans::class);

        return $rows->filter(fn (GoalPlan $p) => $plans->mayDecide($user, $p) && app(AccessScopes::class)->allows($user, $p))
            ->map(fn (GoalPlan $p) => new ApprovalItem(
                id: 'goal_plan:'.$p->id, type: 'goal_plan', typeLabel: 'Performance',
                title: 'Goal sheet · '.($p->cycle?->name ?? 'cycle').' · '.$p->goals.' goal(s)', subject: $p->employee?->display_name, subjectEmployeeId: $p->employee_id,
                reason: null, impact: 'Approving makes the goals active; sending it back returns them to draft. Total weightage '.rtrim(rtrim((string) $p->total_weight, '0'), '.').'.',
                effectiveOn: null, dueAt: $p->submitted_at?->copy()->addDays(3), requestedAt: $p->submitted_at, requestedBy: $p->submitter?->name,
                risk: 'normal', riskReason: null, decisions: ['approve', 'reject'], url: $this->url(fn () => GoalResource::getUrl('index')), record: $p,
                decisionLabels: ['reject' => 'Send back'],
            ))->values()->all();
    }

    /** @return list<ApprovalItem> */
    private function plans(User $user, ?int $about): array
    {
        $rows = ImprovementPlan::query()->with(['employee.person', 'creator'])->whereIn('status', ['pending_approval', 'pending_outcome'])
            ->when($about !== null, fn ($q) => $q->where('employee_id', $about))->orderBy('id')->limit(self::SCAN)->get();
        $rows = $this->withoutWorkflow($rows, ImprovementPlan::class);
        app(AccessScopes::class)->primeEmployeeIds($user, $rows->pluck('employee_id'));
        $plans = app(ImprovementPlans::class);

        return $rows->filter(fn (ImprovementPlan $p) => $plans->mayDecide($user, $p) && app(AccessScopes::class)->allows($user, $p))
            ->map(function (ImprovementPlan $p) {
                $evaluation = $p->status === 'pending_outcome';

                return new ApprovalItem(
                    id: ($evaluation ? 'pip_evaluation:' : 'pip:').$p->id, type: $evaluation ? 'pip_evaluation' : 'pip', typeLabel: 'Performance',
                    title: $evaluation ? 'Improvement plan outcome: '.config("peopleos.performance.pip_statuses.{$p->requested_outcome}", (string) $p->requested_outcome) : 'Improvement plan from '.$p->start_date->format('d M Y'),
                    subject: $p->employee?->display_name, subjectEmployeeId: $p->employee_id, reason: null,
                    impact: $evaluation ? 'Approving records the outcome; sending it back returns the plan to be evaluated again.' : 'Approving starts the plan until '.$p->end_date->format('d M Y').'; rejecting cancels it.',
                    effectiveOn: $evaluation ? null : $p->start_date, dueAt: $p->updated_at?->copy()->addDays(3), requestedAt: $p->updated_at, requestedBy: $p->creator?->name,
                    risk: 'normal', riskReason: null, decisions: ['approve', 'reject'], url: $this->url(fn () => ImprovementPlanResource::getUrl('index')), record: $p,
                    decisionLabels: $evaluation ? ['approve' => 'Approve outcome', 'reject' => 'Send back'] : [],
                );
            })->values()->all();
    }

    /**
     * @template T of Model
     *
     * @param  Collection<int, T>  $rows
     * @return Collection<int, T>
     */
    private function withoutWorkflow(Collection $rows, string $class): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }
        $open = WorkflowInstance::query()->whereIn('subject_type', [$class, (new $class)->getMorphClass()])->whereIn('subject_id', $rows->pluck('id'))
            ->whereIn('status', InstanceStatus::inFlight())->pluck('subject_id')->map(fn ($id) => (int) $id)->all();

        return $rows->reject(fn (Model $r) => in_array((int) $r->getKey(), $open, true))->values();
    }

    private function url(\Closure $url): ?string
    {
        try {
            return $url();
        } catch (Throwable) {
            return null;
        }
    }
}
