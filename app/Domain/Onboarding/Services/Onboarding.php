<?php

namespace App\Domain\Onboarding\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Models\FormSubmission;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Feedback\Services\FeedbackRequests;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Notifications\Services\NotificationContext;
use App\Domain\Notifications\Services\NotificationEngine;
use App\Domain\Onboarding\Events\OnboardingCompleted;
use App\Domain\Onboarding\Events\OnboardingTaskAssigned;
use App\Domain\Onboarding\Models\OnboardingPlan;
use App\Domain\Onboarding\Models\OnboardingTask;
use App\Domain\Onboarding\Models\OnboardingTemplate;
use App\Domain\Onboarding\Models\OnboardingTemplateItem;
use App\Domain\Onboarding\Support\ProfileSections;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Template selection, plan generation, task completion and progress (§21). HROne setup parity: induction programmes are
 * templates of kind induction (their own plan); templates may start N days before joining; items may need a profile
 * section filled, a declaration confirmed or a feedback given; items can be issued to current employees.
 */
final class Onboarding
{
    public function __construct(
        private readonly RuleEngine $rules,
        private readonly EmployeeRuleContext $context,
        private readonly AuditRecorder $audit,
        private readonly Timeline $timeline,
        private readonly NotificationEngine $notifications,
        private readonly NotificationContext $notificationContext,
    ) {}

    /** The best matching active template of a kind (onboarding checklist or induction programme) by priority, or null. */
    public function templateFor(Employee $employee, string $kind = 'onboarding'): ?OnboardingTemplate
    {
        $context = $this->context->build($employee);

        return OnboardingTemplate::query()
            ->where('status', 'active')
            ->where('kind', $kind)
            ->orderBy('priority')->orderBy('id')
            ->get()
            ->first(fn (OnboardingTemplate $t) => $this->rules->matches($t->conditions ?? [], $context, 'all'));
    }

    public function start(Employee $employee, ?OnboardingTemplate $template = null, ?string $reason = null): OnboardingPlan
    {
        $kind = $template?->kind ?? 'onboarding';
        if ($employee->onboardingPlans()->where('kind', $kind)->where('status', 'in_progress')->exists()) {
            throw new RuntimeException($kind === 'induction' ? 'This employee already has an induction in progress.' : 'This employee already has an onboarding plan in progress.');
        }

        $template ??= $this->templateFor($employee) ?? throw new RuntimeException('No onboarding template matches this employee.');
        $anchor = $employee->joining_date ?? $employee->expected_joining_date ?? now();

        return DB::transaction(function () use ($employee, $template, $anchor, $reason) {
            $plan = new OnboardingPlan([
                'employee_id' => $employee->id,
                'onboarding_template_id' => $template->id,
                'kind' => $template->kind ?? 'onboarding',
                'anchor_date' => $anchor,
                'started_by' => auth()->id(),
                'started_at' => now(),
            ]);
            $plan->withAuditReason($reason)->save();

            foreach ($template->items()->get() as $item) {
                $this->createTask($plan, $employee, $item, $template);
            }

            $label = $plan->kind === 'induction' ? 'Induction' : 'Onboarding';
            $this->timeline->record($employee, 'onboarding', "{$label} started ({$template->name})", now(), $reason, $plan);
            $this->notifications->fire('onboarding.started', $this->notificationContext->build($employee, ['onboarding' => ['template' => $template->name, 'plan' => $plan->id]]), $plan);

            return $plan->refresh();
        });
    }

    /**
     * HROne setup parity 08.2: whether a template should start for the employee today. A template that starts N days
     * before joining waits until then (peopleos:onboarding:start-due starts it); without N it starts at once, as before.
     */
    public function startsNow(OnboardingTemplate $template, Employee $employee, ?CarbonInterface $today = null): bool
    {
        $anchor = $employee->joining_date ?? $employee->expected_joining_date;
        if ($template->starts_days_before_joining === null || $anchor === null) {
            return true;
        }

        return Carbon::parse($anchor)->startOfDay()->subDays((int) $template->starts_days_before_joining)->lte(($today ?? now())->copy()->startOfDay());
    }

    /**
     * Daily: start the plans (onboarding checklists and induction programmes) whose template starts N days before joining
     * and whose joiner is now within those days. Idempotent: one plan of a kind per employee.
     */
    public function startDue(?CarbonInterface $today = null): int
    {
        $today = ($today ?? now())->copy()->startOfDay();
        if (! OnboardingTemplate::query()->where('status', 'active')->whereNotNull('starts_days_before_joining')->exists()) {
            return 0;
        }
        $started = 0;
        // One employee at a time: starting a plan reads the employee's relations (strict mode refuses lazy loads in a batch).
        Employee::query()->whereIn('lifecycle_state', [LifecycleState::Preboarding->value, LifecycleState::Onboarding->value, LifecycleState::Joined->value])->orderBy('id')->pluck('id')
            ->each(function ($id) use ($today, &$started) {
                $employee = Employee::query()->find($id);
                foreach (array_keys((array) config('peopleos.onboarding.kinds')) as $kind) {
                    if ($employee->onboardingPlans()->where('kind', $kind)->exists()) {
                        continue;
                    }
                    $template = $this->templateFor($employee, $kind);
                    if ($template === null || $template->starts_days_before_joining === null || ! $this->startsNow($template, $employee, $today)) {
                        continue;
                    }
                    $this->start($employee, $template, 'Started '.$template->starts_days_before_joining.' day(s) before joining');
                    $started++;
                }
            });

        return $started;
    }

    /** @return list<int> current employees (working here now) the template's conditions match */
    public function currentEmployeesFor(OnboardingTemplate $template): array
    {
        return Employee::query()->with('person')->employed()->orderBy('id')->get()
            ->filter(fn (Employee $employee) => $this->rules->matches($template->conditions ?? [], $this->context->build($employee), 'all'))
            ->map(fn (Employee $employee) => (int) $employee->id)->values()->all();
    }

    /**
     * HROne setup parity P13 ("apply this change to: New employee / All employee"): issue template items to current
     * employees as a plan of kind task_campaign. Only items an employee has not had since the item last changed are issued.
     *
     * @param  list<int>  $itemIds
     * @param  iterable<Employee>  $employees
     * @return array{employees: int, tasks: int}
     */
    public function issueItems(OnboardingTemplate $template, array $itemIds, iterable $employees, ?string $reason = null): array
    {
        $items = $template->items()->whereIn('id', $itemIds)->get();
        $counts = ['employees' => 0, 'tasks' => 0];
        if ($items->isEmpty()) {
            return $counts;
        }
        foreach ($employees as $employee) {
            $issued = DB::transaction(function () use ($template, $items, $employee, $reason) {
                $had = OnboardingTask::query()->whereIn('onboarding_template_item_id', $items->pluck('id'))
                    ->whereHas('plan', fn ($q) => $q->where('employee_id', $employee->id))->get(['onboarding_template_item_id', 'created_at'])
                    ->groupBy('onboarding_template_item_id');
                $due = $items->filter(fn (OnboardingTemplateItem $item) => ! isset($had[$item->id]) || $had[$item->id]->max('created_at')->lt($item->updated_at ?? $item->created_at))->values();
                if ($due->isEmpty()) {
                    return 0;
                }
                $plan = new OnboardingPlan(['employee_id' => $employee->id, 'onboarding_template_id' => $template->id, 'kind' => 'task_campaign', 'anchor_date' => now()->startOfDay(), 'started_by' => auth()->id(), 'started_at' => now()]);
                $plan->withAuditReason($reason ?? "Issued from {$template->name} to current employees")->save();
                foreach ($due as $item) {
                    // Items issued later are due from today: the phase offsets count from joining.
                    $this->createTask($plan, $employee, $item, $template, fromToday: true);
                }

                return $due->count();
            });
            if ($issued > 0) {
                $counts['employees']++;
                $counts['tasks'] += $issued;
            }
        }
        $this->audit->record(AuditAction::Update, 'onboarding', $template, reason: $reason ?? 'Issued to current employees', metadata: ['issued_to_current_employees' => $counts, 'items' => $items->pluck('title')->all()]);

        return $counts;
    }

    public function complete(OnboardingTask $task, ?string $note = null, ?Model $evidence = null, ?User $actor = null, bool $declared = false, ?string $ip = null): OnboardingTask
    {
        $actor ??= auth()->user();
        $task->loadMissing('plan.employee');

        if ($task->status !== 'pending') {
            throw new RuntimeException('This task is already closed.');
        }

        if ($actor && ! $task->isActionableBy($actor) && ! $actor->hasPermission('onboarding.manage')) {
            throw new RuntimeException('This task is not assigned to you.');
        }

        // HROne setup parity P13: a profile section must be filled, a declaration confirmed, a feedback given.
        if ($task->type === 'profile_section' && filled($task->profile_section) && ! ProfileSections::filled($task->plan->employee, $task->profile_section)) {
            throw new RuntimeException(ProfileSections::label($task->profile_section).' is not filled in yet.');
        }
        if (filled($task->declaration_text) && ! $declared) {
            throw new RuntimeException('Confirm the declaration first.');
        }
        if ($task->type === 'feedback' && app(FeedbackRequests::class)->pendingFor($task, (string) ($task->feedbackTemplate()->value('process') ?? '')) !== null) {
            throw new RuntimeException('Give the feedback first.');
        }

        $task->update([
            'status' => 'completed',
            'note' => $note,
            'completed_by' => $actor?->id,
            'completed_at' => now(),
            'employee_document_id' => $evidence instanceof EmployeeDocument ? $evidence->id : $task->employee_document_id,
            'form_submission_id' => $evidence instanceof FormSubmission ? $evidence->id : $task->form_submission_id,
        ] + (filled($task->declaration_text) ? ['declared_at' => now(), 'declared_ip' => $ip] : []));

        $this->audit->record(AuditAction::Update, 'onboarding', $task->plan, changes: [['field' => $task->title, 'before' => 'pending', 'after' => 'completed']], reason: $note,
            metadata: ['task_id' => $task->id] + (filled($task->declaration_text) ? ['declared' => true, 'declared_ip' => $ip] : []), actor: $actor);
        $this->recompute($task->plan);

        return $task;
    }

    public function skip(OnboardingTask $task, string $note, ?User $actor = null): OnboardingTask
    {
        $actor ??= auth()->user();
        $task->loadMissing('plan.employee');

        if ($task->status !== 'pending') {
            throw new RuntimeException('This task is already closed.');
        }

        $task->update(['status' => 'skipped', 'note' => $note, 'completed_by' => $actor?->id, 'completed_at' => now()]);
        app(FeedbackRequests::class)->cancelFor($task, 'Onboarding item skipped');
        $this->audit->record(AuditAction::Update, 'onboarding', $task->plan, changes: [['field' => $task->title, 'before' => 'pending', 'after' => 'skipped']], reason: $note, metadata: ['task_id' => $task->id], actor: $actor);
        $this->recompute($task->plan);

        return $task;
    }

    public function cancel(OnboardingPlan $plan, ?string $reason = null): OnboardingPlan
    {
        $plan->tasks()->where('status', 'pending')->update(['status' => 'skipped', 'note' => $reason]);
        $plan->withAuditReason($reason)->update(['status' => 'cancelled', 'completed_at' => now()]);

        return $plan;
    }

    public function recompute(OnboardingPlan $plan): OnboardingPlan
    {
        $plan->loadMissing('employee');
        $tasks = $plan->tasks()->get();
        $total = $tasks->count();
        $closed = $tasks->whereIn('status', ['completed', 'skipped'])->count();
        $progress = $total === 0 ? 100 : (int) floor($closed * 100 / $total);
        $mandatoryOpen = $tasks->where('is_mandatory', true)->where('status', 'pending')->count();

        $plan->forceFill(['progress' => $progress])->saveQuietly();

        if ($plan->status === 'in_progress' && $mandatoryOpen === 0 && $tasks->where('status', 'pending')->count() === 0) {
            $plan->withAuditReason('All tasks closed')->update(['status' => 'completed', 'completed_at' => now()]);
            // Only the onboarding checklist completes onboarding; an induction is recorded, items issued later just close.
            if ($plan->kind === 'onboarding') {
                $this->timeline->record($plan->employee, 'onboarding', 'Onboarding completed', now(), null, $plan);
                $this->notifications->fire('onboarding.completed', $this->notificationContext->build($plan->employee, ['onboarding' => ['plan' => $plan->id]]), $plan);
                OnboardingCompleted::dispatch($plan);
            } elseif ($plan->kind === 'induction') {
                $this->timeline->record($plan->employee, 'onboarding', 'Induction completed', now(), null, $plan);
            }
        }

        return $plan;
    }

    private function createTask(OnboardingPlan $plan, Employee $employee, OnboardingTemplateItem $item, ?OnboardingTemplate $template = null, bool $fromToday = false): OnboardingTask
    {
        [$userId, $roleId] = $this->owner($employee, $item);
        $offset = $item->due_offset_days ?? config("peopleos.onboarding.phases.{$item->phase}.offset", 0);
        // HROne setup parity 08.2: a template that starts N days before joining has its preboarding items due then.
        if ($item->due_offset_days === null && $item->phase === 'preboarding' && $template?->starts_days_before_joining !== null) {
            $offset = -1 * (int) $template->starts_days_before_joining;
        }
        if ($fromToday) {
            $offset = max(0, (int) ($item->due_offset_days ?? 0));
        }

        $task = OnboardingTask::create([
            'onboarding_plan_id' => $plan->id,
            'onboarding_template_item_id' => $item->id,
            'phase' => $item->phase,
            'type' => $item->type,
            'title' => $item->title,
            'description' => $item->description,
            'owner_user_id' => $userId,
            'owner_role_id' => $roleId,
            'document_type_id' => $item->document_type_id,
            'form_id' => $item->form_id,
            'profile_section' => $item->profile_section,
            'feedback_template_id' => $item->feedback_template_id,
            'declaration_text' => $item->declaration_text,
            'due_on' => $plan->anchor_date->copy()->addDays((int) $offset),
            'is_mandatory' => $item->is_mandatory,
            'sort_order' => $item->sort_order,
        ]);

        // HROne setup parity P13: a feedback item (e.g. the induction evaluation) asks its owner on the template.
        if ($item->type === 'feedback' && $item->feedback_template_id !== null) {
            $template = $item->feedbackTemplate()->first();
            if ($template !== null) {
                $employee->loadMissing('person');
                app(FeedbackRequests::class)->raise($template->id, $template->process, $task, $employee, 'owner', $userId, $roleId, $task->due_on,
                    $item->title.' — '.($employee->person?->full_name ?? $employee->employee_code));
            }
        }

        OnboardingTaskAssigned::dispatch($task);

        return $task;
    }

    /** @return array{0: ?int, 1: ?int} [user id, role id] */
    private function owner(Employee $employee, OnboardingTemplateItem $item): array
    {
        $employee->loadMissing('currentManager.manager');

        return match ($item->owner_type) {
            'employee' => [$employee->user_id, null],
            'manager' => [$employee->currentManager?->manager?->user_id, null],
            'buddy' => [$employee->reportingRelationships()->where('type', 'buddy')->effectiveOn()->first()?->manager?->user_id, null],
            'hrbp' => [$employee->reportingRelationships()->where('type', 'hrbp')->effectiveOn()->first()?->manager?->user_id, null],
            'role' => [null, $item->owner_role_id],
            'user' => [$item->owner_user_id, null],
            default => [null, null],
        };
    }
}
