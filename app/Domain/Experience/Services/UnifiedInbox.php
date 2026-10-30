<?php

namespace App\Domain\Experience\Services;

use App\Domain\Configuration\Models\FormSubmission;
use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Models\ExpenseReportPart;
use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Expense\Models\TravelRequestPart;
use App\Domain\Experience\Contracts\RequestType;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Experience\Support\InboxEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Onboarding\Models\OnboardingTask;
use App\Domain\Recognition\Services\RewardPoints;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\InboxArchives;
use App\Domain\Workflow\Services\SystemTasks;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\TaskInbox;
use App\Filament\Resources\Initiatives\InitiativeResource;
use App\Filament\Resources\OneOnOnes\OneOnOneResource;
use App\Filament\Resources\Recognitions\RecognitionResource;
use App\Filament\Resources\RewardPoints\RewardPointEntryResource;
use App\Filament\Resources\WorkflowInstances\WorkflowInstanceResource;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * HROne parity RQ-8 (matrix 20 §20.2.1): the unified inbox — one read model over what already waits for the person:
 * their Approval Center items (ApprovalCenter::pending, every source, delegation and archive rules included), their own
 * tasks (ExperienceTasks — My HR → Tasks — without workflow steps, which the Approval Center already lists) and the
 * onboarding tasks they own (the task inbox's "Onboarding tasks" tab). It adds nothing anybody could not already see:
 * every row comes from one of those reads, made as the person.
 *
 * On top it groups rows by request type (facets named by the RQ-1 request registry, with "Forms" for configuration-form
 * runs and the workflow's name for other workflow steps), filters them (unread, due, relieved employees, employee, the
 * requested-at range, leave type, ticket category, travel request, organisation unit) and searches them; and it lists the
 * closed rows of "Missed by me" (workflow steps of the person's that were decided without them) and "Not relevant"
 * (system tasks closed as not relevant, SystemTasks). Deciding stays with ApprovalDecisions and the owning service.
 * Request-scoped (memo per tenant and user).
 */
final class UnifiedInbox
{
    /** The views of the inbox: the "All messages" choice and the quick filters (HROne §20.2.1 #9, #12) plus Archive / Not relevant. */
    public const SHOWS = [
        'all' => 'All', 'unread' => 'Unread', 'overdue' => 'Overdue', 'due_today' => 'Due today', 'due_tomorrow' => 'Due tomorrow',
        'missed' => 'Missed by me', 'relieved' => 'Relieved employees', 'archived' => 'Archive', 'not_relevant' => 'Not relevant',
    ];

    /** "Missed by me" reaches this far back. */
    public const MISSED_DAYS = 30;

    /** Filters that only some request types bring (HROne loads the filter set per category, R.3.2 #1). */
    public const FAMILY_FILTERS = ['leave_type' => 'leave_type_id', 'ticket_category' => 'ticket_category_id', 'travel' => 'travel'];

    /** Approval Center kinds kept as their own facet even when their record is a registered request type. */
    private const OWN_FACET = ['expense_exceptional', 'loan_guarantee', 'leave_donation'];

    /** Parts approved separately, grouped with their request. */
    private const PARENTS = [TravelRequestPart::class => TravelRequest::class, ExpenseReportPart::class => ExpenseReport::class];

    /** Labels of the person's own task kinds (ExperienceTask domain.kind or domain). */
    private const TASK_LABELS = [
        'knowledge.acknowledge' => 'Policy acknowledgement', 'communication.acknowledge' => 'Announcement', 'assets.acknowledge' => 'Asset acknowledgement',
        'performance.feedback_360' => '360 feedback', 'performance.feedback_360_nomination' => '360 nominations', 'performance.period_review' => 'Periodic review',
        'performance.one_on_one_feedback' => 'One-on-one feedback', 'performance.initiative_feedback' => 'Initiative feedback', 'feedback' => 'Feedback',
        'engagement' => 'Survey', 'surveys' => 'Survey',
    ];

    private const UNIT_LABELS = ['company' => 'Company', 'business_unit' => 'Business unit', 'division' => 'Division', 'department' => 'Department', 'location' => 'Location', 'team' => 'Team'];

    /** @var array<string, array<string, Collection<int, InboxEntry>>> memo key => show => rows */
    private array $memo = [];

    public function __construct(
        private readonly ApprovalCenter $center,
        private readonly RequestRegistry $registry,
        private readonly TenantContext $tenants,
    ) {}

    /** Whether the person has an inbox: they decide something (Approvals) or have tasks (Task inbox). */
    public static function opensFor(User $user): bool
    {
        return $user->hasPermission('task.view') || $user->hasPermission('task.view_all') || Approvals::canAccess();
    }

    /**
     * The rows of one view, newest work first within urgency (decisions in the Approval Center's order, then tasks by due).
     *
     * @return Collection<int, InboxEntry>
     */
    public function rows(User $user, string $show = 'all'): Collection
    {
        $show = isset(self::SHOWS[$show]) ? $show : 'all';
        $now = now();

        return match ($show) {
            'missed' => $this->cached($user, 'missed', fn () => $this->missed($user)),
            'archived' => $this->cached($user, 'archived', fn () => $this->archived($user)),
            'not_relevant' => $this->cached($user, 'not_relevant', fn () => $this->notRelevant($user)),
            'unread' => $this->pending($user)->reject(fn (InboxEntry $e) => in_array($e->id, $this->readIds($user), true))->values(),
            'overdue' => $this->pending($user)->filter(fn (InboxEntry $e) => $e->isOverdue($now))->values(),
            'due_today' => $this->pending($user)->filter(fn (InboxEntry $e) => $e->isDueToday($now))->values(),
            'due_tomorrow' => $this->pending($user)->filter(fn (InboxEntry $e) => $e->isDueTomorrow($now))->values(),
            'relieved' => $this->pending($user)->filter(fn (InboxEntry $e) => ($e->meta['relieved'] ?? false) === true)->values(),
            default => $this->pending($user),
        };
    }

    /** @return array<string, int> how many rows each view holds (the pending views from one read) */
    public function counts(User $user): array
    {
        $out = [];
        foreach (array_keys(self::SHOWS) as $show) {
            $out[$show] = in_array($show, ['missed', 'archived', 'not_relevant'], true) && ! $this->closedListsOffered($user, $show) ? 0 : $this->rows($user, $show)->count();
        }

        return $out;
    }

    /**
     * Narrow rows by facet, keyword and the filter panel.
     *
     * @param  Collection<int, InboxEntry>  $rows
     * @param  array<string, mixed>  $filters  employee, from, until, leave_type, ticket_category, travel, unit
     * @return Collection<int, InboxEntry>
     */
    public function filter(Collection $rows, ?string $facet = null, ?string $search = null, array $filters = []): Collection
    {
        $search = Str::lower(trim((string) $search));
        $employee = Str::lower(trim((string) ($filters['employee'] ?? '')));
        $travel = Str::lower(trim((string) ($filters['travel'] ?? '')));
        $from = $this->date($filters['from'] ?? null)?->startOfDay();
        $until = $this->date($filters['until'] ?? null)?->endOfDay();
        $leaveType = filled($filters['leave_type'] ?? null) ? (int) $filters['leave_type'] : null;
        $category = filled($filters['ticket_category'] ?? null) ? (int) $filters['ticket_category'] : null;
        $unit = filled($filters['unit'] ?? null) ? (string) $filters['unit'] : null;

        return $rows->filter(function (InboxEntry $e) use ($facet, $search, $employee, $travel, $from, $until, $leaveType, $category, $unit) {
            if (filled($facet) && $e->facet !== $facet) {
                return false;
            }
            if ($search !== '' && ! str_contains((string) ($e->meta['haystack'] ?? ''), $search)) {
                return false;
            }
            if ($employee !== '' && ! str_contains(Str::lower((string) $e->subject), $employee) && ! str_contains(Str::lower((string) ($e->meta['employee_code'] ?? '')), $employee)) {
                return false;
            }
            if (($from !== null || $until !== null) && ($e->requestedAt === null || ($from !== null && $e->requestedAt->lt($from)) || ($until !== null && $e->requestedAt->gt($until)))) {
                return false;
            }
            if ($leaveType !== null && (int) ($e->meta['leave_type_id'] ?? 0) !== $leaveType) {
                return false;
            }
            if ($category !== null && (int) ($e->meta['ticket_category_id'] ?? 0) !== $category) {
                return false;
            }
            if ($travel !== '' && ! str_contains(Str::lower((string) ($e->meta['travel'] ?? '')), $travel)) {
                return false;
            }

            return $unit === null || array_key_exists($unit, (array) ($e->meta['units'] ?? []));
        })->values();
    }

    /**
     * The request-type facets of some rows, with counts, by label.
     *
     * @param  Collection<int, InboxEntry>  $rows
     * @return array<string, array{label: string, count: int}>
     */
    public function facets(Collection $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row->facet] ??= ['label' => $row->facetLabel, 'count' => 0];
            $out[$row->facet]['count']++;
        }
        uasort($out, fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));

        return $out;
    }

    /**
     * The filter panel's choices for some rows: the family filters only when a row has them (per facet), the organisation
     * units of the employees the rows are about (only employees the person may see).
     *
     * @param  Collection<int, InboxEntry>  $rows
     * @return array{leave_type: array<int, string>, ticket_category: array<int, string>, travel: bool, unit: array<string, string>}
     */
    public function filterOptions(Collection $rows): array
    {
        $leaveTypes = $rows->pluck('meta.leave_type_id')->filter()->unique()->values();
        $categories = $rows->pluck('meta.ticket_category_id')->filter()->unique()->values();
        $units = [];
        foreach ($rows as $row) {
            $units += (array) ($row->meta['units'] ?? []);
        }
        asort($units);

        return [
            'leave_type' => $leaveTypes->isEmpty() ? [] : LeaveType::query()->whereIn('id', $leaveTypes)->orderBy('name')->pluck('name', 'id')->all(),
            'ticket_category' => $categories->isEmpty() ? [] : TicketCategory::query()->whereIn('id', $categories)->orderBy('name')->pluck('name', 'id')->all(),
            'travel' => $rows->contains(fn (InboxEntry $e) => filled($e->meta['travel'] ?? null)),
            'unit' => $units,
        ];
    }

    /** A row of one of the person's views by id (re-read; never trusted from the browser). */
    public function find(User $user, string $id, string $show = 'all'): ?InboxEntry
    {
        return $this->rows($user, $show)->first(fn (InboxEntry $e) => $e->id === $id)
            ?? ($show !== 'all' ? $this->pending($user)->first(fn (InboxEntry $e) => $e->id === $id) : null);
    }

    public function forget(User $user): void
    {
        unset($this->memo[$this->key($user)]);
        $this->center->forget($user);
    }

    /** @return list<string> ids of the inbox items the person has opened */
    public function readIds(User $user): array
    {
        return (array) app(ExperiencePreferences::class)->for($user)['inbox_read'];
    }

    public function markRead(User $user, string $id): void
    {
        if ($this->pending($user)->contains(fn (InboxEntry $e) => $e->id === $id)) {
            app(ExperiencePreferences::class)->markInboxRead($user, $id);
        }
    }

    // ---- the profile peek and "More" -------------------------------------------------------------------------------

    /**
     * §20.2.1 #21: a peek at the employee a row is about — the directory fields PersonPeek gives people the viewer may find
     * (PeopleVisibility, so access scopes apply; the status and profile link only when the Employee 360 opens for them) and
     * the CA-6 social-profile sections the organisation shows. Null when the viewer may not see that person.
     *
     * @return array<string, mixed>|null
     */
    public function peek(User $viewer, InboxEntry $entry): ?array
    {
        if ($entry->subjectEmployeeId === null) {
            return null;
        }
        $peek = app(PersonPeek::class)->for($viewer, $entry->subjectEmployeeId);
        if ($peek === null) {
            return null;
        }
        $employee = app(PeopleVisibility::class)->query($viewer)->find($entry->subjectEmployeeId);
        $peek['sections'] = $employee instanceof Employee ? app(DirectorySections::class)->for($viewer, $employee) : [];

        return $peek;
    }

    /**
     * §20.2.1 #22–25: "More" — links that open the existing forms (give a badge, give reward points, schedule a one-on-one,
     * create an initiative), each only when the viewer may open that screen and use that form, never about oneself.
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    public function more(User $viewer, InboxEntry $entry): array
    {
        if ($entry->subjectEmployeeId === null || ! app(PeopleVisibility::class)->canSee($viewer, $entry->subjectEmployeeId)) {
            return [];
        }
        $employee = Employee::query()->find($entry->subjectEmployeeId);
        if ($employee === null || ($employee->user_id !== null && (int) $employee->user_id === (int) $viewer->id)) {
            return [];
        }
        $id = (int) $employee->id;
        $access = app(ScreenAccess::class);
        $links = [];
        $offer = function (string $key, string $label, callable $url, callable $may) use (&$links, $viewer, $access) {
            try {
                $href = $url();
                if (ScreenAccess::as($viewer, $may) && $access->allowsFor($viewer, $href)) {
                    $links[] = ['key' => $key, 'label' => $label, 'url' => $href];
                }
            } catch (Throwable $e) {
                report($e);
            }
        };
        $offer('badge', 'Give a badge', fn () => RecognitionResource::getUrl('index', ['action' => 'giveBadge', 'actionArguments' => ['employee' => $id]]),
            fn () => $viewer->can('recognition.give'));
        $offer('points', 'Give reward points', fn () => RewardPointEntryResource::getUrl('index', ['action' => 'givePoints', 'actionArguments' => ['employee' => $id]]),
            fn () => app(RewardPoints::class)->enabled() && $viewer->can('recognition.give') && app(RoleLens::class)->employee($viewer) !== null);
        $offer('one_on_one', 'Schedule a one-on-one', fn () => OneOnOneResource::getUrl('index', ['action' => 'create']), fn () => OneOnOneResource::canCreate());
        $offer('initiative', 'Create an initiative', fn () => InitiativeResource::getUrl('index', ['action' => 'create']), fn () => InitiativeResource::canCreate());

        return $links;
    }

    /** Whether the person may mark the row's task not relevant now (system tasks, task.ignore). */
    public function mayIgnore(User $user, InboxEntry $entry): bool
    {
        $task = $entry->item?->record;

        return $entry->isDecision() && $entry->system && ! $entry->item->isDelegated() && $task instanceof WorkflowTask && app(SystemTasks::class)->mayIgnore($user, $task);
    }

    // ---- gathering ---------------------------------------------------------------------------------------------------

    /** @return Collection<int, InboxEntry> */
    private function pending(User $user): Collection
    {
        return $this->cached($user, 'pending', function () use ($user) {
            if (! $this->tenants->has()) {
                return collect();
            }
            $items = $this->center->pending($user);
            $tasks = $this->ownTasks($user);
            $onboarding = $this->onboardingTasks($user);
            $context = $this->context($user, $items, $tasks);

            return collect([
                ...$items->map(fn (ApprovalItem $item) => $this->decisionEntry($item, $context, 'decision'))->all(),
                ...$tasks->map(fn (ExperienceTask $task) => $this->taskEntry($task, $context))->all(),
                ...$onboarding->map(fn (OnboardingTask $task) => $this->onboardingEntry($task))->all(),
            ])->values();
        });
    }

    /** @return Collection<int, ExperienceTask> the person's own tasks except workflow steps (the Approval Center lists those) */
    private function ownTasks(User $user): Collection
    {
        if (! $this->signedIn($user)) {
            return collect();
        }
        try {
            return app(ExperienceTasks::class)->for($user, app(CurrentEmployee::class)->of($user))->reject(fn (ExperienceTask $t) => $t->domain === 'workflow')->values();
        } catch (Throwable $e) {
            report($e);

            return collect();
        }
    }

    /** @return Collection<int, OnboardingTask> what the task inbox's "Onboarding tasks" tab lists for the person */
    private function onboardingTasks(User $user): Collection
    {
        if (! $user->hasPermission('task.view') && ! $user->hasPermission('task.view_all')) {
            return collect();
        }

        return OnboardingTask::query()->with(['plan.employee.person'])->where('status', 'pending')->actionableBy($user)->orderBy('due_on')->limit(200)->get();
    }

    /** @return Collection<int, InboxEntry> */
    private function archived(User $user): Collection
    {
        $context = $this->context($user, $items = $this->center->archived($user), collect());

        return $items->map(fn (ApprovalItem $item) => $this->decisionEntry($item, $context, 'archived'))->values();
    }

    /**
     * Workflow steps assigned to the person (or one of their roles) that closed without their response in the last
     * MISSED_DAYS days — the step was decided by the other approvers (parallel / majority), HROne's "MISSED TO RESPOND".
     *
     * @return Collection<int, InboxEntry>
     */
    private function missed(User $user): Collection
    {
        if (! $user->hasPermission('task.view') && ! $user->hasPermission('task.view_all')) {
            return collect();
        }

        return WorkflowTask::query()->with(['instance.workflow', 'instance.starter'])->where('status', TaskStatus::Skipped)->whereNull('decision')->actionableBy($user)
            ->where('updated_at', '>=', now()->subDays(self::MISSED_DAYS))->latest('updated_at')->limit(100)->get()
            ->map(fn (WorkflowTask $t) => new InboxEntry(
                id: 'workflow_task:'.$t->id, source: 'missed', facet: 'workflow:'.($t->instance?->workflow_id ?? 0), facetLabel: $t->instance?->workflow?->name ?? 'Workflow',
                title: $t->title, subject: $t->instance?->subject_label, requestedAt: $t->created_at, dueAt: $t->due_at, from: $t->instance?->starter?->name ?? 'System',
                url: $t->instance && $user->hasPermission('workflow.view') ? $this->safe(fn () => WorkflowInstanceResource::getUrl('view', ['record' => $t->instance])) : null,
                taskId: (int) $t->id, meta: ['haystack' => Str::lower($t->title.' '.$t->instance?->subject_label.' '.$t->instance?->workflow?->name)],
            ))->values();
    }

    /** @return Collection<int, InboxEntry> system tasks of the person's marked not relevant (restorable) */
    private function notRelevant(User $user): Collection
    {
        if (! $user->hasPermission('task.view') && ! $user->hasPermission('task.view_all')) {
            return collect();
        }

        return app(SystemTasks::class)->notRelevantFor($user)->map(fn (WorkflowTask $t) => new InboxEntry(
            id: 'workflow_task:'.$t->id, source: 'not_relevant', facet: 'workflow:'.($t->instance?->workflow_id ?? 0), facetLabel: $t->instance?->workflow?->name ?? 'Workflow',
            title: $t->title, subject: $t->instance?->subject_label, requestedAt: $t->created_at, dueAt: $t->due_at,
            from: $t->completer?->name, taskId: (int) $t->id, note: $t->note,
            meta: ['haystack' => Str::lower($t->title.' '.$t->instance?->subject_label.' '.$t->note), 'closed_at' => $t->completed_at],
        ))->values();
    }

    /** The closed lists are offered to people with a task inbox; "Not relevant" also to holders of task.ignore only. */
    private function closedListsOffered(User $user, string $show): bool
    {
        return match ($show) {
            'archived' => app(InboxArchives::class)->active(),
            default => $user->hasPermission('task.view') || $user->hasPermission('task.view_all'),
        };
    }

    /**
     * What the rows need beyond the items: workflow subjects (one query per subject type), the employees the rows are about
     * (as the person may see them: access scopes apply, so nobody's code or unit is read past the person's scope).
     *
     * @param  Collection<int, ApprovalItem>  $items
     * @param  Collection<int, ExperienceTask>  $tasks
     * @return array{subjects: array<string, Model>, employees: array<int, Employee>}
     */
    private function context(User $user, Collection $items, Collection $tasks): array
    {
        $subjects = [];
        $byType = [];
        foreach ($items as $item) {
            $run = $item->record instanceof WorkflowTask ? $this->runOf($item->record) : null;
            if ($run?->subject_type !== null && $run->subject_id !== null) {
                $byType[$run->subject_type][] = $run->subject_id;
            }
        }
        foreach ($byType as $type => $ids) {
            $class = Relation::getMorphedModel($type) ?? $type;
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }
            try {
                // The run is in the person's queue: its subject is read to group and filter it (as RequestTimeline::requestOf does).
                $class::query()->withoutGlobalScope(AccessScope::class)->whereKey(array_values(array_unique($ids)))->get()
                    ->each(function (Model $m) use (&$subjects, $type) {
                        $subjects[$type.':'.$m->getKey()] = $m;
                    });
            } catch (Throwable $e) {
                report($e);
            }
        }

        $employeeIds = $items->pluck('subjectEmployeeId')->filter()
            ->concat(collect($subjects)->map(fn (Model $m) => $m instanceof Employee ? $m->getKey() : ($m->getAttributes()['employee_id'] ?? null))->filter())
            ->concat($tasks->map(fn (ExperienceTask $t) => $t->meta['employee_id'] ?? null)->filter())
            ->map(fn ($id) => (int) $id)->unique()->values();
        $employees = $employeeIds->isEmpty() ? [] : Employee::query()->with(['person', 'currentPosition.company', 'currentPosition.businessUnit', 'currentPosition.division',
            'currentPosition.department', 'currentPosition.location', 'currentPosition.team'])->whereKey($employeeIds->all())->get()->keyBy('id')->all();

        return ['subjects' => $subjects, 'employees' => $employees];
    }

    /** @param array{subjects: array<string, Model>, employees: array<int, Employee>} $context */
    private function decisionEntry(ApprovalItem $item, array $context, string $source): InboxEntry
    {
        $task = $item->record instanceof WorkflowTask ? $item->record : null;
        $run = $task !== null ? $this->runOf($task) : null;
        $subject = $run !== null ? ($context['subjects'][$run->subject_type.':'.$run->subject_id] ?? null) : null;
        $record = $task !== null ? $subject : $item->record;
        [$facet, $label] = $this->facetOf($item, $task, $run, $record);
        $employeeId = $item->subjectEmployeeId ?? match (true) {
            $record instanceof Employee => (int) $record->getKey(),
            ($id = $record?->getAttributes()['employee_id'] ?? null) !== null => (int) $id,
            default => null,
        };
        $employee = $employeeId !== null ? ($context['employees'][$employeeId] ?? null) : null;
        $meta = $this->employeeMeta($employee) + array_filter([
            'leave_type_id' => $record !== null ? ($record->getAttributes()['leave_type_id'] ?? null) : null,
            'ticket_category_id' => $record instanceof Ticket ? $record->ticket_category_id : null,
            'travel' => $this->travelOf($record),
        ], fn ($v) => $v !== null && $v !== '');
        $system = $task !== null && SystemTasks::isSystem($task);
        $overall = $record instanceof FormSubmission && $run !== null ? 'Workflow '.mb_strtolower($run->status->getLabel()).' · form '.str_replace('_', ' ', (string) $record->status) : null;
        $meta['haystack'] = Str::lower(implode(' ', array_filter([$item->title, $item->subject, $label, $item->typeLabel, $item->reason, $item->requestedBy, $meta['employee_code'] ?? null, $meta['travel'] ?? null, implode(' ', $item->facts)])));

        return new InboxEntry(
            id: $item->id, source: $source, facet: $facet, facetLabel: $label, title: $item->title, subject: $item->subject, subjectEmployeeId: $item->subjectEmployeeId ?? ($employee?->id),
            requestedAt: $item->requestedAt, dueAt: $item->dueAt, from: $item->requestedBy ?? ($system ? 'System' : null), url: $item->url, item: $item,
            system: $system, overallStatus: $overall, meta: $meta, taskId: $task?->id,
        );
    }

    /** @param array{subjects: array<string, Model>, employees: array<int, Employee>} $context */
    private function taskEntry(ExperienceTask $task, array $context): InboxEntry
    {
        [$facet, $label] = $task->domain === 'servicedesk'
            ? ['helpdesk', $this->registry->find('helpdesk')?->label() ?? 'Helpdesk']
            : ['task:'.$task->domain.'.'.$task->kind, self::TASK_LABELS[$task->domain.'.'.$task->kind] ?? self::TASK_LABELS[$task->domain] ?? Str::headline($task->domain.' '.$task->kind)];
        $employeeId = isset($task->meta['employee_id']) ? (int) $task->meta['employee_id'] : null;
        $employee = $employeeId !== null ? ($context['employees'][$employeeId] ?? null) : null;
        $meta = $this->employeeMeta($employee) + array_filter(['ticket_category_id' => $task->meta['ticket_category_id'] ?? null]);
        $meta['haystack'] = Str::lower(implode(' ', array_filter([$task->title, $task->reference, $label])));
        $requested = $task->meta['requested_at'] ?? null;

        return new InboxEntry(
            id: 'task:'.substr(md5($task->domain.'|'.$task->kind.'|'.$task->reference.'|'.$task->url.'|'.$task->title), 0, 24), source: 'task', facet: $facet, facetLabel: $label,
            title: $task->title, subject: $employee?->display_name, subjectEmployeeId: $employee?->id, requestedAt: $requested instanceof CarbonInterface ? $requested : null,
            dueAt: $task->dueAt, url: $task->url, meta: $meta,
        );
    }

    private function onboardingEntry(OnboardingTask $task): InboxEntry
    {
        $employee = $task->plan?->employee;

        return new InboxEntry(
            id: 'onboarding_task:'.$task->id, source: 'onboarding', facet: 'onboarding', facetLabel: 'Onboarding tasks', title: $task->title,
            subject: $employee?->display_name, subjectEmployeeId: $employee?->id, requestedAt: $task->created_at, dueAt: $task->due_on?->copy()->endOfDay(),
            url: $this->safe(fn () => TaskInbox::getUrl()), taskId: (int) $task->id,
            meta: ['haystack' => Str::lower($task->title.' '.$employee?->display_name.' '.$task->description), 'employee_code' => $employee?->employee_code],
        );
    }

    /** @return array{0: string, 1: string} facet key and label */
    private function facetOf(ApprovalItem $item, ?WorkflowTask $task, ?WorkflowInstance $run, ?Model $record): array
    {
        if ($task !== null) {
            if ($record instanceof FormSubmission) {
                return ['forms', 'Forms'];
            }
            if ($record !== null && ($type = $this->typeOf($record)) !== null) {
                return [$type->key(), $type->label()];
            }

            return ['workflow:'.($run?->workflow_id ?? 0), $run?->workflow?->name ?? 'Workflow'];
        }
        $kinds = ApprovalCenter::kinds();
        if (str_ends_with($item->type, '_cancellation') || in_array($item->type, self::OWN_FACET, true) || $record === null) {
            return [$item->type, $kinds[$item->type] ?? $item->typeLabel];
        }
        $type = $this->typeOf($record);

        return $type !== null ? [$type->key(), $type->label()] : [$item->type, $kinds[$item->type] ?? $item->typeLabel];
    }

    private function typeOf(Model $record): ?RequestType
    {
        try {
            if (isset(self::PARENTS[$record::class])) {
                foreach ($this->registry->all() as $type) {
                    if ($type->model() === self::PARENTS[$record::class]) {
                        return $type;
                    }
                }

                return null;
            }

            return $this->registry->forRecord($record);
        } catch (Throwable) {
            return null;
        }
    }

    private function travelOf(?Model $record): ?string
    {
        $trip = match (true) {
            $record instanceof TravelRequest => $record,
            $record instanceof TravelRequestPart && $record->relationLoaded('trip') => $record->trip,
            default => null,
        };

        return $trip !== null ? trim($trip->number.' '.$trip->purpose) : null;
    }

    /** @return array<string, mixed> the employee's code, organisation units and whether they have left */
    private function employeeMeta(?Employee $employee): array
    {
        if ($employee === null) {
            return [];
        }
        $units = [];
        $position = $employee->currentPosition;
        foreach (self::UNIT_LABELS as $dimension => $label) {
            $relation = Str::camel($dimension);
            $id = $position?->getAttributes()[$dimension.'_id'] ?? null;
            if ($id !== null && ($name = $position->{$relation}?->name ?? null) !== null) {
                $units[$dimension.':'.$id] = $label.' · '.$name;
            }
        }

        return [
            'employee_code' => $employee->employee_code,
            'units' => $units,
            'relieved' => in_array($employee->lifecycle_state, [LifecycleState::Exited, LifecycleState::Alumni], true),
        ];
    }

    private function runOf(WorkflowTask $task): ?WorkflowInstance
    {
        try {
            return $task->relationLoaded('instance') ? $task->instance : $task->instance()->with('workflow')->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  callable(): Collection<int, InboxEntry>  $build
     * @return Collection<int, InboxEntry>
     */
    private function cached(User $user, string $what, callable $build): Collection
    {
        return $this->memo[$this->key($user)][$what] ??= $build();
    }

    private function key(User $user): string
    {
        return $this->tenants->id().':'.$user->id;
    }

    private function signedIn(User $user): bool
    {
        return auth()->check() && (int) auth()->id() === (int) $user->getKey();
    }

    private function date(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function safe(callable $url): ?string
    {
        try {
            return $url();
        } catch (Throwable) {
            return null;
        }
    }
}
