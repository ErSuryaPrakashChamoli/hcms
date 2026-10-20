<?php

namespace App\Domain\Experience\Services;

use App\Domain\Analytics\Services\WorkforceMetrics;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Services\CompensationAccess;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\LearningCertificate;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\ChangeIntelligencePage;
use App\Filament\Pages\LeaveCalendar;
use App\Filament\Pages\MyHr;
use App\Filament\Pages\People;
use App\Filament\Pages\WorkforceCommandCentre;
use App\Filament\Resources\CompensationChanges\CompensationChangeResource;
use App\Filament\Resources\LearningCertificates\LearningCertificateResource;
use App\Filament\Resources\LearningEnrolments\LearningEnrolmentResource;
use App\Filament\Resources\OnboardingPlans\OnboardingPlanResource;
use App\Filament\Resources\Tickets\TicketResource;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * UX: smart search intents (§29). A fixed, reviewed set of question patterns, each mapped to one
 * whitelisted, permission-checked query over the system of record. There is no natural-language-to-SQL:
 * a query that matches no pattern simply produces no smart answer. Answers carry a count, up to five
 * examples the viewer may see, and a link to the full list.
 */
final class IntentSearch
{
    public function __construct(private readonly PeopleVisibility $people, private readonly ApprovalCenter $approvals) {}

    /** @return list<array{key: string, title: string, answer: string, rows: list<array{label: string, meta: ?string, person_id: ?int}>, url: ?string}> */
    public function answer(User $user, string $query): array
    {
        $q = mb_strtolower(trim($query));
        if (mb_strlen($q) < 3) {
            return [];
        }
        $out = [];
        foreach ($this->intents() as $key => [$pattern, $resolver]) {
            if (! preg_match($pattern, $q, $m)) {
                continue;
            }
            try {
                $result = $resolver($user, $m);
            } catch (Throwable $e) {
                report($e);
                $result = null;
            }
            if ($result !== null) {
                $out[] = ['key' => $key] + $result;
            }
            if (count($out) >= 3) {
                break;
            }
        }

        return $out;
    }

    /** @return array<string, array{0: string, 1: callable}> */
    private function intents(): array
    {
        return [
            'joining' => ['/\b(join(ing|ers?)|new (hires?|joiners?)|starting)\b/', fn (User $u) => $this->joining($u)],
            'probation' => ['/\bprobation\b/', fn (User $u) => $this->probation($u)],
            'pending_leave' => ['/\b(pending|waiting).*\bleave\b|\bleave\b.*\b(pending|approvals?|to approve)\b/', fn (User $u) => $this->pendingLeave($u)],
            'on_leave' => ['/\b(on leave|away|out of office|absent)\b.*\b(today|now)?\b|\bwho\b.*\bleave\b/', fn (User $u) => $this->onLeaveToday($u)],
            'approvals' => ['/\b(pending|my)\s+approvals?\b|\bapprovals?\s+(pending|waiting)\b|\bto approve\b/', fn (User $u) => $this->pendingApprovals($u)],
            'certificates' => ['/\bcert(ificate|ification)?s?\b.*\bexpir|\bexpir\w*\b.*\bcert/', fn (User $u) => $this->certificates($u)],
            'tickets' => ['/\b(open|my)\s+(service\s+)?(requests?|tickets?|cases?)\b|\bservice requests?\b/', fn (User $u) => $this->openRequests($u)],
            'comp_changes' => ['/\b(comp(ensation)?|salary|pay)\s+(changes?|revisions?)\b/', fn (User $u) => $this->compensationChanges($u)],
            'completed_training' => ['/\bcompleted\s+(?<course>[a-z0-9 &\-]{3,40}?)\s*(training|course|programme|program)\b/', fn (User $u, array $m) => $this->completedTraining($u, trim($m['course']))],
            'reporting_to' => ['/\b(reporting|reports?)\s+to\s+(?<manager>[a-z][a-z\'\- ]{1,40})$/', fn (User $u, array $m) => $this->reportingTo($u, trim($m['manager']), null)],
            'people_in_reporting' => ['/\bpeople\s+in\s+(?<dept>[a-z][a-z&\- ]{1,40}?)\s+(reporting|reports?)\s+to\s+(?<manager>[a-z][a-z\'\- ]{1,40})$/', fn (User $u, array $m) => $this->reportingTo($u, trim($m['manager']), trim($m['dept']))],
            'headcount' => ['/\bhead\s?count\b/', fn (User $u) => $this->headcount($u)],
            'org_changes' => ['/\b(recent|latest)\s+(organi[sz]ation(al)?\s+|org\s+)?changes?\b|\b(organi[sz]ation(al)?|org)\s+changes?\b|\bwhat\s+changed\b|\bchanges?\s+(this|last)\s+(week|month)\b/', fn (User $u) => $this->orgChanges($u)],
        ];
    }

    private function joining(User $u): ?array
    {
        if (! $u->hasPermission('employee.view')) {
            return null;
        }
        $query = Employee::query()->with('person')->whereIn('lifecycle_state', [LifecycleState::PreEmployee->value, LifecycleState::Preboarding->value])
            ->whereBetween('expected_joining_date', [now()->startOfDay(), now()->addDays(14)->endOfDay()])->orderBy('expected_joining_date');

        return $this->peopleAnswer('Joining in the next 14 days', $query, fn (Employee $e) => 'Joins '.$e->expected_joining_date?->format('D, d M'),
            rescue(fn () => OnboardingPlanResource::getUrl('index'), null, false));
    }

    private function probation(User $u): ?array
    {
        if (! $u->hasPermission('employee.view')) {
            return null;
        }
        $query = Employee::query()->with('person')->where('lifecycle_state', LifecycleState::Probation->value)
            ->whereDate('probation_end_date', '<=', now()->addDays(30))->orderBy('probation_end_date');

        return $this->peopleAnswer('Probation ending within 30 days (or overdue)', $query,
            fn (Employee $e) => ($e->probation_end_date?->isPast() ? 'Overdue since ' : 'Ends ').$e->probation_end_date?->format('d M'), People::canAccess() ? People::getUrl(['status' => 'probation']) : null);
    }

    private function pendingLeave(User $u): ?array
    {
        $items = $this->approvals->pending($u)->filter(fn ($i) => in_array($i->type, ['leave', 'leave_cancellation'], true) || ($i->type === 'workflow_task' && $i->record?->instance?->subject_type === (new LeaveRequest)->getMorphClass()));
        if (! $u->hasPermission('leave.approve') && $items->isEmpty()) {
            return null;
        }

        return ['title' => 'Leave waiting for your decision', 'answer' => $items->count().' '.($items->count() === 1 ? 'request' : 'requests'),
            'rows' => $items->take(5)->map(fn ($i) => ['label' => $i->subject ?? $i->title, 'meta' => $i->title, 'person_id' => $i->subjectEmployeeId])->values()->all(),
            'url' => Approvals::canAccess() ? Approvals::getUrl() : null];
    }

    private function onLeaveToday(User $u): ?array
    {
        $today = now()->toDateString();
        $visible = $this->people->query($u)->select('employees.id');
        $query = LeaveRequest::query()->with(['employee.person', 'leaveType'])->where('status', 'approved')->whereIn('employee_id', $visible)
            ->whereDate('from_date', '<=', $today)->whereDate('to_date', '>=', $today);
        if (! $u->hasPermission('leave.view') && ! app(RoleLens::class)->has($u, RoleLens::MANAGER)) {
            return null;
        }
        $count = (clone $query)->count();

        return ['title' => 'On leave today', 'answer' => $count.' '.($count === 1 ? 'person' : 'people'),
            'rows' => $query->limit(5)->get()->map(fn (LeaveRequest $r) => ['label' => $r->employee?->display_name ?? '—', 'meta' => ($r->leaveType?->name ?? 'Leave').' · until '.$r->to_date->format('d M'), 'person_id' => $r->employee_id])->all(),
            'url' => LeaveCalendar::canAccess() ? LeaveCalendar::getUrl() : null];
    }

    private function pendingApprovals(User $u): ?array
    {
        if (! Approvals::canAccess()) {
            return null;
        }
        $items = $this->approvals->pending($u);

        return ['title' => 'Your pending approvals', 'answer' => $items->count().' waiting',
            'rows' => $items->take(5)->map(fn ($i) => ['label' => $i->title, 'meta' => $i->typeLabel.($i->subject ? ' · '.$i->subject : ''), 'person_id' => $i->subjectEmployeeId])->values()->all(),
            'url' => Approvals::getUrl()];
    }

    private function certificates(User $u): ?array
    {
        if (! LearningCertificateResource::canAccess()) {
            return null;
        }
        $query = LearningCertificate::query()->with(['employee.person'])->whereNotNull('expires_on')->whereDate('expires_on', '<=', now()->addDays(60))
            ->whereIn('employee_id', $this->people->query($u)->select('employees.id'))->orderBy('expires_on');
        $count = (clone $query)->count();

        return ['title' => 'Certifications expiring within 60 days', 'answer' => $count.' '.($count === 1 ? 'certificate' : 'certificates'),
            'rows' => $query->limit(5)->get()->map(fn (LearningCertificate $c) => ['label' => $c->employee?->display_name ?? '—', 'meta' => ($c->expires_on->isPast() ? 'Expired ' : 'Expires ').$c->expires_on->format('d M Y'), 'person_id' => $c->employee_id])->all(),
            'url' => LearningCertificateResource::getUrl('index')];
    }

    private function openRequests(User $u): ?array
    {
        $access = app(CaseAccess::class);
        if ($u->hasPermission('servicedesk.agent') || $u->hasPermission('servicedesk.view')) {
            $query = $access->visible(Ticket::query()->with(['service', 'category', 'employee.person']), $u)->whereIn('tickets.status', Ticket::OPEN)->orderBy('tickets.due_at');
            $url = TicketResource::canAccess() ? TicketResource::getUrl('index') : null;
            $title = 'Open HR requests';
        } else {
            $me = app(RoleLens::class)->employee($u);
            if ($me === null) {
                return null;
            }
            $query = $access->visible(Ticket::query()->with(['service', 'category']), $u)->where('tickets.employee_id', $me->id)->whereIn('tickets.status', Ticket::OPEN);
            $url = MyHr::canAccess() ? MyHr::getUrl(['tab' => 'requests']) : null;
            $title = 'Your open HR requests';
        }
        $count = (clone $query)->count();

        return ['title' => $title, 'answer' => $count.' open', 'rows' => $query->limit(5)->get()->map(fn (Ticket $t) => ['label' => $t->number.' · '.$t->serviceName(), 'meta' => str_replace('_', ' ', (string) $t->status), 'person_id' => null])->all(), 'url' => $url];
    }

    private function compensationChanges(User $u): ?array
    {
        if (! CompensationChangeResource::canAccess()) {
            return null;
        }
        $access = app(CompensationAccess::class);
        $changes = CompensationChange::query()->with('employee.person')->whereBetween('effective_from', [now()->startOfMonth(), now()->endOfMonth()])
            ->whereNotIn('status', ['cancelled', 'rejected'])->orderBy('effective_from')->limit(100)->get()->filter(fn ($c) => $access->mayViewChange($u, $c));

        return ['title' => 'Compensation changes effective this month', 'answer' => $changes->count().' '.($changes->count() === 1 ? 'change' : 'changes'),
            'rows' => $changes->take(5)->map(fn ($c) => ['label' => $c->employee?->display_name ?? '—', 'meta' => $c->typeLabel().' · '.(CompensationChange::STATUSES[$c->status] ?? $c->status), 'person_id' => $c->employee_id])->values()->all(),
            'url' => CompensationChangeResource::getUrl('index')];
    }

    private function completedTraining(User $u, string $course): ?array
    {
        if (! LearningEnrolmentResource::canAccess() || mb_strlen($course) < 3) {
            return null;
        }
        $query = LearningEnrolment::query()->with(['employee.person', 'course'])->where('status', 'completed')
            ->whereHas('course', fn (Builder $c) => $c->where('title', 'like', '%'.$course.'%'))
            ->whereIn('employee_id', $this->people->query($u)->select('employees.id'))->latest('completed_at');
        $count = (clone $query)->count();

        return ['title' => 'Completed “'.$course.'” training', 'answer' => $count.' '.($count === 1 ? 'person' : 'people'),
            'rows' => $query->limit(5)->get()->map(fn (LearningEnrolment $e) => ['label' => $e->employee?->display_name ?? '—', 'meta' => $e->course?->title, 'person_id' => $e->employee_id])->all(),
            'url' => LearningEnrolmentResource::getUrl('index')];
    }

    private function reportingTo(User $u, string $manager, ?string $department): ?array
    {
        $managers = PeopleVisibility::matchName($this->people->query($u)->with('person'), $manager)->limit(3)->get();
        if ($managers->isEmpty()) {
            return null;
        }
        $query = $this->people->query($u)->with(['person', 'currentPosition.department'])
            ->whereHas('reportingRelationships', fn (Builder $r) => $r->whereIn('manager_id', $managers->pluck('id'))->where('is_primary', true)->currentlyEffective())
            ->when($department, fn (Builder $q) => $q->whereHas('currentPosition.department', fn (Builder $d) => $d->where('name', 'like', '%'.$department.'%')));
        $count = (clone $query)->count();
        $who = $managers->map(fn ($m) => $m->display_name)->implode(' / ');

        return ['title' => ($department ? 'People in '.ucwords($department).' ' : 'People ').'reporting to '.$who, 'answer' => $count.' '.($count === 1 ? 'person' : 'people'),
            'rows' => $query->limit(5)->get()->map(fn (Employee $e) => ['label' => $e->display_name ?? '—', 'meta' => $e->currentPosition?->department?->name, 'person_id' => $e->id])->all(),
            'url' => People::canAccess() ? People::getUrl(['manager' => $managers->first()->id]) : null];
    }

    private function headcount(User $u): ?array
    {
        $metrics = app(WorkforceMetrics::class);
        if (! $u->hasPermission('analytics.view') && ! $u->hasPermission('analytics.executive')) {
            return null;
        }
        $m = $metrics->metric('headcount', $u);

        return ['title' => 'Headcount today', 'answer' => number_format((int) $m['value']).' people', 'rows' => [],
            'url' => WorkforceCommandCentre::canAccess() ? WorkforceCommandCentre::getUrl() : null];
    }

    /**
     * "Show recent organisation changes": the viewer's own permission-aware change feed (ChangeFeed: the
     * timeline categories they may see, for the people they may see), summarised in business language.
     * Announcements are left out; nothing here reads raw audit records.
     */
    private function orgChanges(User $u): ?array
    {
        $items = app(ChangeFeed::class)->for($u, 50, 30)->reject(fn (array $i) => $i['type'] === 'announcement')->values();
        if ($items->isEmpty()) {
            return $u->hasPermission('employee.view') ? ['title' => 'Organisation changes, last 30 days', 'answer' => 'No changes you can see', 'rows' => [], 'url' => null] : null;
        }
        $summary = $items->groupBy('label')->map(fn ($g, $label) => $g->count().' '.mb_strtolower((string) $label))->values()->implode(', ');

        return ['title' => 'Organisation changes, last 30 days', 'answer' => $items->count().' '.($items->count() === 1 ? 'change' : 'changes').' · '.$summary,
            'rows' => $items->take(5)->map(fn (array $i) => ['label' => $i['subject'] ?? $i['title'], 'meta' => $i['title'].' · '.$i['at']->format('d M'), 'person_id' => $i['subject_id']])->all(),
            'url' => ChangeIntelligencePage::canAccess() ? ChangeIntelligencePage::getUrl() : null];
    }

    /** @param Builder<Employee> $query */
    private function peopleAnswer(string $title, Builder $query, callable $meta, ?string $url): array
    {
        $count = (clone $query)->count();

        return ['title' => $title, 'answer' => $count.' '.($count === 1 ? 'person' : 'people'),
            'rows' => $query->limit(5)->get()->map(fn (Employee $e) => ['label' => $e->display_name ?? $e->employee_code, 'meta' => $meta($e), 'person_id' => $e->id])->all(), 'url' => $url];
    }
}
