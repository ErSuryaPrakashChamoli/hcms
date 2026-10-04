<?php

namespace App\Domain\Experience\Services;

use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Services\CompensationAccess;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\LeaveYear;
use App\Domain\Letters\Models\Letter;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Filament\Resources\AttendanceRegularisations\AttendanceRegularisationResource;
use App\Filament\Resources\CompensationChanges\CompensationChangeResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Resources\WorkflowInstances\WorkflowInstanceResource;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * UX: the Approval Center read model. It gathers the decisions waiting for the viewer from the systems
 * of record (workflow tasks, leave, regularisations, compensation changes, letters) and keeps only those
 * the viewer's existing policies allow them to decide. It owns no state and decides nothing:
 * ApprovalDecisions sends each decision to the owning domain service, which checks again.
 */
final class ApprovalCenter
{
    private const SCAN = 200;

    /** @var array<string, Collection<int, ApprovalItem>> */
    private array $memo = [];

    /** @var array<int, int> */
    private array $teamAway = [];

    public function __construct(private readonly TenantContext $tenants) {}

    /** @return Collection<int, ApprovalItem> pending decisions, most urgent first */
    public function pending(User $user): Collection
    {
        if (! $this->tenants->has()) {
            return collect();
        }
        $key = $this->tenants->id().':'.$user->id;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $items = collect([
            fn () => $this->workflowTasks($user),
            fn () => $this->leaveRequests($user),
            fn () => $this->regularisations($user),
            fn () => $this->compensationChanges($user),
            fn () => $this->letters($user),
        ])->flatMap(function (callable $source) {
            try {
                return $source();
            } catch (Throwable $e) {
                report($e);

                return [];
            }
        });
        $order = ['urgent' => 0, 'today' => 1, 'upcoming' => 2];

        return $this->memo[$key] = $items->sortBy(fn (ApprovalItem $i) => [$order[$i->group()], $i->dueAt?->timestamp ?? $i->effectiveOn?->timestamp ?? PHP_INT_MAX])->values();
    }

    /** @return array{urgent: Collection, today: Collection, upcoming: Collection, completed: Collection} */
    public function grouped(User $user): array
    {
        $pending = $this->pending($user)->groupBy(fn (ApprovalItem $i) => $i->group());

        return ['urgent' => $pending->get('urgent', collect()), 'today' => $pending->get('today', collect()), 'upcoming' => $pending->get('upcoming', collect()), 'completed' => $this->completed($user)];
    }

    /** A pending item by id, re-resolved and re-authorised (never trusted from the browser). */
    public function find(User $user, string $id): ?ApprovalItem
    {
        unset($this->memo[$this->tenants->id().':'.$user->id]);

        return $this->pending($user)->first(fn (ApprovalItem $i) => $i->id === $id);
    }

    /** Cheap count for the navigation badge (cached briefly, cleared after each decision). */
    public function count(User $user): int
    {
        if (! $this->tenants->has()) {
            return 0;
        }

        return (int) Cache::remember($this->countKey($user), 60, fn () => $this->pending($user)->count());
    }

    public function forget(User $user): void
    {
        unset($this->memo[$this->tenants->id().':'.$user->id]);
        Cache::forget($this->countKey($user));
    }

    /** Decisions the viewer took in the last two weeks (their own trail, newest first). */
    public function completed(User $user, int $days = 14): Collection
    {
        if (! $this->tenants->has()) {
            return collect();
        }
        $since = now()->subDays($days);
        $rows = collect();
        WorkflowTask::query()->with('instance')->where('completed_by', $user->id)->where('completed_at', '>=', $since)->latest('completed_at')->limit(20)->get()
            ->each(fn (WorkflowTask $t) => $rows->push(['title' => $t->title, 'type' => 'Workflow', 'subject' => $t->instance?->subject_label, 'status' => $t->status->getLabel(), 'at' => $t->completed_at, 'url' => $t->instance ? $this->safeUrl(fn () => WorkflowInstanceResource::getUrl('view', ['record' => $t->instance])) : null]));
        LeaveRequest::query()->with(['employee.person', 'leaveType'])->where('reviewed_by', $user->id)->where('reviewed_at', '>=', $since)->latest('reviewed_at')->limit(20)->get()
            ->each(fn (LeaveRequest $r) => $rows->push(['title' => ($r->leaveType?->name ?? 'Leave').' · '.$this->days($r->days), 'type' => 'Leave', 'subject' => $r->employee?->display_name, 'status' => ucfirst((string) $r->status), 'at' => $r->reviewed_at, 'url' => $this->safeUrl(fn () => LeaveRequestResource::getUrl('index'))]));
        AttendanceRegularisation::query()->with('employee.person')->where('reviewed_by', $user->id)->where('reviewed_at', '>=', $since)->latest('reviewed_at')->limit(20)->get()
            ->each(fn (AttendanceRegularisation $r) => $rows->push(['title' => 'Attendance correction · '.$r->date?->format('d M'), 'type' => 'Attendance', 'subject' => $r->employee?->display_name, 'status' => ucfirst((string) $r->status), 'at' => $r->reviewed_at, 'url' => $this->safeUrl(fn () => AttendanceRegularisationResource::getUrl('index'))]));

        return $rows->sortByDesc(fn ($r) => $r['at']?->timestamp ?? 0)->take(25)->values();
    }

    /** @return list<ApprovalItem> */
    private function workflowTasks(User $user): array
    {
        $tasks = WorkflowTask::query()->with(['instance.workflow'])->where('status', TaskStatus::Pending)->actionableBy($user)->orderBy('due_at')->limit(self::SCAN)->get();
        $items = [];
        foreach ($tasks as $task) {
            if (! $user->can('act', $task)) {
                continue;
            }
            $instance = $task->instance;
            $subject = $this->subjectOf($instance);
            $employee = $subject instanceof Employee ? $subject : ($subject !== null && isset($subject->employee_id) ? Employee::query()->with('person')->find($subject->employee_id) : null);
            $isApproval = $task->type === 'approval';
            $items[] = new ApprovalItem(
                id: 'workflow_task:'.$task->id,
                type: 'workflow_task',
                typeLabel: $instance?->workflow?->name ?? 'Workflow',
                title: $task->title,
                subject: $employee?->display_name ?? $instance?->subject_label,
                subjectEmployeeId: $employee?->id,
                reason: $task->instructions,
                impact: $subject instanceof LeaveRequest ? $this->leaveImpact($subject) : null,
                effectiveOn: $subject instanceof LeaveRequest ? $subject->from_date : null,
                dueAt: $task->due_at,
                requestedAt: $task->created_at,
                requestedBy: $instance?->starter?->name ?? null,
                risk: $task->escalation_level > 0 ? 'high' : 'normal',
                riskReason: $task->escalation_level > 0 ? 'Escalated '.$task->escalation_level.' time(s)' : null,
                decisions: $isApproval ? ['approve', 'reject'] : ['complete'],
                url: $instance ? $this->safeUrl(fn () => WorkflowInstanceResource::getUrl('view', ['record' => $instance])) : null,
                record: $task,
                changes: $subject instanceof LeaveRequest ? $this->leaveChanges($subject) : [],
            );
        }

        return $items;
    }

    /** @return list<ApprovalItem> */
    private function leaveRequests(User $user): array
    {
        if (! $user->hasPermission('leave.approve')) {
            return [];
        }
        // employee.currentManager is read by teamAway(); eager-loaded so strict mode (no lazy loading) never drops the source.
        $requests = LeaveRequest::query()->with(['employee.person', 'employee.currentManager', 'leaveType', 'requester'])->whereIn('status', ['pending', 'cancel_requested'])->orderBy('from_date')->limit(self::SCAN)->get();
        // A request with a running workflow is decided through its workflow task (listed above).
        $inWorkflow = WorkflowInstance::query()->where('subject_type', (new LeaveRequest)->getMorphClass())->whereIn('subject_id', $requests->pluck('id'))
            ->whereIn('status', [InstanceStatus::Running, InstanceStatus::Waiting])->pluck('subject_id')->all();
        $items = [];
        foreach ($requests as $request) {
            if (in_array($request->id, $inWorkflow, false) || ! $user->can('approve', $request)) {
                continue;
            }
            $cancellation = $request->status === 'cancel_requested';
            [$risk, $riskReason] = $this->leaveRisk($request);
            $items[] = new ApprovalItem(
                id: 'leave:'.$request->id,
                type: $cancellation ? 'leave_cancellation' : 'leave',
                typeLabel: $cancellation ? 'Leave cancellation' : 'Leave',
                title: ($cancellation ? 'Cancel ' : '').($request->leaveType?->name ?? 'Leave').' · '.$this->days($request->days),
                subject: $request->employee?->display_name,
                subjectEmployeeId: $request->employee_id,
                reason: $request->reason,
                impact: $this->leaveImpact($request),
                effectiveOn: $request->from_date,
                dueAt: null,
                requestedAt: $request->created_at,
                requestedBy: $request->requester?->name,
                risk: $risk,
                riskReason: $riskReason,
                decisions: ['approve', 'reject'],
                url: $this->safeUrl(fn () => LeaveRequestResource::getUrl('index')),
                record: $request,
                changes: $cancellation ? [] : $this->leaveChanges($request),
                facts: array_values(array_filter([$this->range($request->from_date, $request->to_date), $request->contact_details ? 'Reachable: '.$request->contact_details : null])),
                decisionLabels: $cancellation ? ['approve' => 'Approve cancellation', 'reject' => 'Keep leave'] : [],
            );
        }

        return $items;
    }

    /** @return list<ApprovalItem> */
    private function regularisations(User $user): array
    {
        if (! $user->hasPermission('attendance.approve') && ! $user->hasPermission('attendance.manage')) {
            return [];
        }
        $items = [];
        foreach (AttendanceRegularisation::query()->with(['employee.person', 'requester'])->where('status', 'pending')->orderBy('date')->limit(self::SCAN)->get() as $r) {
            if (! $user->can('approve', $r)) {
                continue;
            }
            $items[] = new ApprovalItem(
                id: 'regularisation:'.$r->id,
                type: 'regularisation',
                typeLabel: 'Attendance',
                title: config("peopleos.attendance.regularisation_types.{$r->type}", ucfirst((string) $r->type)).' · '.$r->date?->format('D, d M'),
                subject: $r->employee?->display_name,
                subjectEmployeeId: $r->employee_id,
                reason: $r->reason,
                impact: 'Attendance for '.$r->date?->format('d M').' is reprocessed on approval',
                effectiveOn: null,
                // Corrections hold up attendance (and payroll inputs): decide within two days.
                dueAt: $r->created_at?->copy()->addDays(2),
                requestedAt: $r->created_at,
                requestedBy: $r->requester?->name,
                risk: $r->date !== null && $r->date->lt(now()->subDays(20)) ? 'high' : 'normal',
                riskReason: $r->date !== null && $r->date->lt(now()->subDays(20)) ? 'Close to the payroll cut-off' : null,
                decisions: ['approve', 'reject'],
                url: $this->safeUrl(fn () => AttendanceRegularisationResource::getUrl('index')),
                record: $r,
                changes: array_values(array_filter([
                    $r->requested_in ? ['label' => 'In', 'before' => null, 'after' => $r->requested_in->format('H:i')] : null,
                    $r->requested_out ? ['label' => 'Out', 'before' => null, 'after' => $r->requested_out->format('H:i')] : null,
                ])),
            );
        }

        return $items;
    }

    /** @return list<ApprovalItem> */
    private function compensationChanges(User $user): array
    {
        $reviewer = $user->hasPermission('compensation.review');
        $approver = $user->hasPermission('compensation.approve');
        if (! $reviewer && ! $approver) {
            return [];
        }
        $access = app(CompensationAccess::class);
        $items = [];
        foreach (CompensationChange::query()->with(['employee.person'])->whereIn('status', CompensationChange::PENDING)->orderBy('effective_from')->limit(self::SCAN)->get() as $change) {
            if ((int) $change->proposed_by === (int) $user->id) {
                continue;
            }
            $actors = array_map('intval', $change->actors());
            $decisions = [];
            $labels = [];
            if ($change->status === 'submitted' && $reviewer) {
                $decisions[] = 'approve';
                $labels['approve'] = 'Mark reviewed';
            } elseif ($change->status === 'under_review' && $approver && ! in_array((int) $user->id, $actors, true)) {
                $decisions[] = 'approve';
            }
            $decisions[] = 'reject';
            $decisions[] = 'request_change';
            $labels['request_change'] = 'Return to proposer';
            $visible = $access->mayViewChange($user, $change);
            $items[] = new ApprovalItem(
                id: 'compensation:'.$change->id,
                type: 'compensation',
                typeLabel: 'Compensation',
                title: $change->typeLabel().' · '.$change->reference,
                subject: $change->employee?->display_name,
                subjectEmployeeId: $change->employee_id,
                reason: $visible ? $change->reason : null,
                impact: $visible && $change->previous_ctc_annual !== null ? $this->money($change->annualIncrease(), $change->currency, signed: true).' a year' : null,
                effectiveOn: $change->effective_from,
                dueAt: null,
                requestedAt: $change->submitted_at ?? $change->created_at,
                requestedBy: User::query()->find($change->proposed_by)?->name,
                risk: $change->effective_from !== null && $change->effective_from->lt(now()) ? 'high' : 'normal',
                riskReason: $change->effective_from !== null && $change->effective_from->lt(now()) ? 'Effective date already passed' : null,
                decisions: $decisions,
                url: $this->safeUrl(fn () => CompensationChangeResource::getUrl('index')),
                record: $change,
                changes: $visible ? array_values(array_filter([
                    ['label' => 'Annual CTC', 'before' => $change->previous_ctc_annual !== null ? $this->money((float) $change->previous_ctc_annual, $change->previous_currency ?? $change->currency) : null, 'after' => $this->money((float) $change->ctc_annual, $change->currency)],
                    $change->increase_percent !== null ? ['label' => 'Increase', 'before' => null, 'after' => rtrim(rtrim(number_format((float) $change->increase_percent, 2), '0'), '.').'%'] : null,
                ])) : [],
                facts: $visible ? [] : ['Amounts are hidden by your compensation access level'],
                decisionLabels: $labels,
            );
        }

        return $items;
    }

    /** @return list<ApprovalItem> */
    private function letters(User $user): array
    {
        if (! $user->hasPermission('letter.issue')) {
            return [];
        }
        $items = [];
        foreach (Letter::query()->with(['employee.person', 'requester'])->where('status', 'pending_approval')->latest('id')->limit(self::SCAN)->get() as $letter) {
            if ($letter->requested_by !== null && (int) $letter->requested_by === (int) $user->id) {
                continue;
            }
            $items[] = new ApprovalItem(
                id: 'letter:'.$letter->id,
                type: 'letter',
                typeLabel: 'Letter',
                title: ($letter->subject ?: config("peopleos.letters.types.{$letter->type}", 'Letter')).' · '.$letter->number,
                subject: $letter->employee?->display_name,
                subjectEmployeeId: $letter->employee_id,
                reason: null,
                impact: 'Issued to the employee once approved',
                effectiveOn: null,
                dueAt: null,
                requestedAt: $letter->created_at,
                requestedBy: $letter->requester?->name,
                risk: 'normal',
                riskReason: null,
                decisions: ['approve', 'reject'],
                url: $this->safeUrl(fn () => LetterResource::getUrl('view', ['record' => $letter])),
                record: $letter,
            );
        }

        return $items;
    }

    private function subjectOf(?WorkflowInstance $instance): mixed
    {
        if ($instance === null || $instance->subject_type === null) {
            return null;
        }
        try {
            return Relation::getMorphedModel($instance->subject_type) !== null || class_exists($instance->subject_type) ? $instance->subject : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function leaveImpact(LeaveRequest $request): ?string
    {
        $overlap = $this->teamAway($request);

        return $overlap > 0 ? $overlap.' other '.($overlap === 1 ? 'person' : 'people').' in the team away on these dates' : 'No one else in the team is away on these dates';
    }

    /** @return list<array{label: string, before: ?string, after: string}> */
    private function leaveChanges(LeaveRequest $request): array
    {
        if ($request->leaveType === null || $request->employee === null || $request->leaveType->category === 'unpaid') {
            return [];
        }
        try {
            $balance = app(LeaveBalances::class)->balance($request->employee, $request->leaveType, app(LeaveYear::class)->periodFor($request->from_date));
        } catch (Throwable) {
            return [];
        }
        $closing = (float) $balance->closing;

        return [['label' => $request->leaveType->name.' balance', 'before' => $this->days($closing), 'after' => $this->days($closing - (float) $request->days)]];
    }

    /** @return array{0: string, 1: ?string} */
    private function leaveRisk(LeaveRequest $request): array
    {
        if ($request->from_date !== null && $request->from_date->lt(now()->startOfDay())) {
            return ['high', 'The leave has already started'];
        }
        if ($this->teamAway($request) >= 2) {
            return ['high', 'Several people in the team are away'];
        }

        return ['normal', null];
    }

    private function teamAway(LeaveRequest $request): int
    {
        if (isset($this->teamAway[$request->id])) {
            return $this->teamAway[$request->id];
        }
        $managerId = $request->employee?->currentManager?->manager_id;
        if ($managerId === null || $request->from_date === null || $request->to_date === null) {
            return $this->teamAway[$request->id] = 0;
        }
        $team = Employee::query()->whereHas('reportingRelationships', fn ($q) => $q->where('manager_id', $managerId)->currentlyEffective())->whereKeyNot($request->employee_id)->pluck('id');

        return $this->teamAway[$request->id] = LeaveRequest::query()->whereIn('employee_id', $team)->where('status', 'approved')
            ->whereDate('from_date', '<=', $request->to_date)->whereDate('to_date', '>=', $request->from_date)->distinct()->count('employee_id');
    }

    private function days(float|string|null $days): string
    {
        $d = (float) $days;

        return rtrim(rtrim(number_format($d, 1), '0'), '.').' '.(abs($d) === 1.0 ? 'day' : 'days');
    }

    private function range(?CarbonInterface $from, ?CarbonInterface $to): ?string
    {
        if ($from === null) {
            return null;
        }

        return $to === null || $from->isSameDay($to) ? $from->format('D, d M') : $from->format('d M').' → '.$to->format('d M');
    }

    private function money(float $amount, ?string $currency, bool $signed = false): string
    {
        return ($signed && $amount > 0 ? '+' : '').($currency ? $currency.' ' : '').number_format($amount, 0);
    }

    private function safeUrl(callable $url): ?string
    {
        try {
            return $url();
        } catch (Throwable) {
            return null;
        }
    }

    private function countKey(User $user): string
    {
        return 'peopleos:approvals:count:'.$this->tenants->id().':'.$user->id;
    }
}
