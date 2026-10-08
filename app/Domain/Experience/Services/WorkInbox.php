<?php

namespace App\Domain\Experience\Services;

use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Experience\Support\ApprovalItem;
use App\Domain\Experience\Support\ExperienceTask;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Letters\Models\Letter;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Filament\Resources\AttendanceRegularisations\AttendanceRegularisationResource;
use App\Filament\Resources\CompensationChanges\CompensationChangeResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Resources\Tickets\TicketResource;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Throwable;

/**
 * UX: My Work, one inbox over the existing read models. Needs attention / Today / Upcoming / Waiting on
 * others / Completed. Approvals come from ApprovalCenter, tasks from ExperienceTasks, personal
 * reminders from NeedsAttention, and "waiting on others" from the viewer's own open requests.
 * Read-only: every row deep-links to the screen (or drawer) that owns the action.
 */
final class WorkInbox
{
    /** NeedsAttention keys already represented by Approval Center items. */
    private const DUPLICATES = ['tasks', 'leave', 'regularisation'];

    public function __construct(
        private readonly ApprovalCenter $approvals,
        private readonly ExperienceTasks $tasks,
        private readonly NeedsAttention $attention,
        private readonly RoleLens $lenses,
    ) {}

    /** @return array{needs_attention: Collection, today: Collection, upcoming: Collection, waiting: Collection, completed: Collection} */
    public function for(User $user): array
    {
        $now = now();
        $out = ['needs_attention' => collect(), 'today' => collect(), 'upcoming' => collect(), 'waiting' => collect(), 'completed' => collect()];

        foreach ($this->approvals->pending($user) as $item) {
            /** @var ApprovalItem $item */
            $group = $item->group($now);
            $out[$group === 'urgent' ? 'needs_attention' : $group]->push($this->row(
                key: $item->id, kind: 'approval', domain: $item->typeLabel, title: $item->title, detail: trim(($item->subject ? $item->subject.' · ' : '').($item->riskReason ?? $item->impact ?? '')),
                due: $item->dueAt ?? $item->effectiveOn, url: $item->url, severity: $group === 'urgent' ? 'danger' : 'warning', approvalId: $item->id,
            ) + ['subject' => $item->subject, 'subject_id' => $item->subjectEmployeeId, 'reason' => $item->riskReason ?? $item->impact]);
        }

        $employee = $this->lenses->employee($user);
        foreach ($this->safe(fn () => $this->tasks->for($user, $employee)) as $task) {
            /** @var ExperienceTask $task */
            if ($task->domain === 'workflow') {
                continue; // workflow tasks are decided in the Approval Center
            }
            $bucket = $task->isOverdue() ? 'needs_attention' : ($task->dueAt !== null && $task->dueAt->lte($now->copy()->endOfDay()) ? 'today' : 'upcoming');
            $out[$bucket]->push($this->row(key: 'task:'.md5($task->domain.$task->reference.$task->title), kind: 'task', domain: ucfirst($task->domain), title: $task->title,
                detail: $task->reference, due: $task->dueAt, url: $task->url, severity: $task->isOverdue() ? 'danger' : 'normal'));
        }

        if ($employee !== null) {
            $reminders = $this->safe(fn () => $this->attention->forEmployee($employee, $user));
            if ($this->lenses->has($user, RoleLens::MANAGER)) {
                $reminders = $reminders->merge($this->safe(fn () => $this->attention->forManager($employee, $user)));
            }
            foreach ($reminders->unique('key') as $r) {
                if (in_array($r['key'], self::DUPLICATES, true)) {
                    continue;
                }
                $bucket = $r['severity'] === 'info' ? 'today' : 'needs_attention';
                $out[$bucket]->push($this->row(key: 'attention:'.$r['key'], kind: 'attention', domain: 'Reminder', title: $r['title'], detail: $r['detail'],
                    due: null, url: $r['url'] ?? null, severity: $r['severity'], count: $r['count']));
            }
            $out['waiting'] = $this->safe(fn () => $this->waiting($user, $employee->id));
        } else {
            $out['waiting'] = $this->safe(fn () => $this->waiting($user, null));
        }

        $out['completed'] = $this->safe(fn () => $this->approvals->completed($user))->map(fn (array $c) => $this->row(key: 'done:'.md5($c['title'].$c['at']), kind: 'done', domain: $c['type'],
            title: $c['title'], detail: trim(($c['subject'] ? $c['subject'].' · ' : '').$c['status']), due: $c['at'], url: $c['url'], severity: 'normal'));

        foreach (['needs_attention', 'today', 'upcoming'] as $key) {
            $out[$key] = $out[$key]->sortBy(fn ($r) => $r['due']?->timestamp ?? PHP_INT_MAX)->values();
        }

        return $out;
    }

    /** Approvals waiting for the viewer (badge on My work). */
    public function attentionCount(User $user): int
    {
        return $this->approvals->count($user);
    }

    /** The viewer's own requests that are with someone else. */
    private function waiting(User $user, ?int $employeeId): Collection
    {
        $rows = collect();
        if ($employeeId !== null) {
            LeaveRequest::query()->with('leaveType')->where('employee_id', $employeeId)->whereIn('status', ['pending', 'cancel_requested'])->orderBy('from_date')->limit(10)->get()
                ->each(fn (LeaveRequest $r) => $rows->push($this->row(key: 'mine-leave:'.$r->id, kind: 'request', domain: 'Leave',
                    title: ($r->status === 'cancel_requested' ? 'Cancellation of ' : '').($r->leaveType?->name ?? 'Leave').' · '.$r->from_date?->format('d M').($r->to_date && ! $r->to_date->isSameDay($r->from_date) ? ' → '.$r->to_date->format('d M') : ''),
                    detail: 'Waiting for approval since '.$r->created_at?->diffForHumans(), due: $r->from_date, url: $this->url(fn () => LeaveRequestResource::getUrl('index')), severity: 'normal')));
            AttendanceRegularisation::query()->where('employee_id', $employeeId)->where('status', 'pending')->orderBy('date')->limit(10)->get()
                ->each(fn (AttendanceRegularisation $r) => $rows->push($this->row(key: 'mine-reg:'.$r->id, kind: 'request', domain: 'Attendance',
                    title: 'Attendance correction · '.$r->date?->format('d M'), detail: 'Waiting for your manager', due: null, url: $this->url(fn () => AttendanceRegularisationResource::getUrl('index')), severity: 'normal')));
            app(CaseAccess::class)->visible(Ticket::query()->with(['service', 'category']), $user)->where('tickets.employee_id', $employeeId)
                ->whereIn('tickets.status', array_diff(Ticket::OPEN, ['waiting_employee']))->limit(10)->get()
                ->each(fn (Ticket $t) => $rows->push($this->row(key: 'mine-ticket:'.$t->id, kind: 'request', domain: 'HR request',
                    title: $t->serviceName().' · '.$t->number, detail: 'With HR · '.str_replace('_', ' ', (string) $t->status), due: $t->due_at, url: $this->url(fn () => TicketResource::getUrl('view', ['record' => $t])), severity: 'normal')));
        }
        Letter::query()->where('requested_by', $user->id)->where('status', 'pending_approval')->limit(10)->get()
            ->each(fn (Letter $l) => $rows->push($this->row(key: 'mine-letter:'.$l->id, kind: 'request', domain: 'Letter', title: ($l->subject ?: 'Letter').' · '.$l->number,
                detail: 'Waiting for approval', due: null, url: $this->url(fn () => LetterResource::getUrl('view', ['record' => $l])), severity: 'normal')));
        if ($user->hasPermission('compensation.propose')) {
            CompensationChange::query()->with('employee.person')->where('proposed_by', $user->id)->whereIn('status', CompensationChange::PENDING)->limit(10)->get()
                ->each(fn (CompensationChange $c) => $rows->push($this->row(key: 'mine-comp:'.$c->id, kind: 'request', domain: 'Compensation',
                    title: $c->typeLabel().' · '.$c->employee?->display_name, detail: CompensationChange::STATUSES[$c->status] ?? $c->status, due: $c->effective_from,
                    url: $this->url(fn () => CompensationChangeResource::getUrl('index')), severity: 'normal')));
        }

        return $rows->values();
    }

    /** @return array<string, mixed> */
    private function row(string $key, string $kind, string $domain, string $title, ?string $detail, ?CarbonInterface $due, ?string $url, string $severity, ?string $approvalId = null, ?int $count = null): array
    {
        return compact('key', 'kind', 'domain', 'title', 'detail', 'due', 'url', 'severity') + ['approval_id' => $approvalId, 'count' => $count];
    }

    private function safe(callable $callback): Collection
    {
        try {
            return collect($callback());
        } catch (Throwable $e) {
            report($e);

            return collect();
        }
    }

    private function url(callable $url): ?string
    {
        try {
            return $url();
        } catch (Throwable) {
            return null;
        }
    }
}
