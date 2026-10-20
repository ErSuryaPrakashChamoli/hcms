<?php

namespace App\Domain\Experience\Services;

use App\Domain\Analytics\Services\WorkforceMetrics;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Communication\Services\Communications;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Models\TrainingSessionAttendee;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Location;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Models\OneOnOne;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Filament\Pages\AnnouncementsFeed;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\LeaveCalendar;
use App\Filament\Pages\MyLearning;
use App\Filament\Pages\MyTeam;
use App\Filament\Pages\MyWork;
use App\Filament\Pages\People;
use App\Filament\Pages\WorkforceCommandCentre;
use App\Filament\Resources\OnboardingPlans\OnboardingPlanResource;
use App\Filament\Resources\OneOnOnes\OneOnOneResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\TrainingSessions\TrainingSessionResource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Throwable;

/**
 * UX: the Home building blocks (KPI strip, your day, team pulse, insights, company pulse and the
 * organisation banner). Every block reads an existing system of record under the viewer's own
 * permissions and relationships; a block the viewer may not see is simply absent.
 */
final class HomeBlocks
{
    /** Attendance statuses that count as working, and those that count as scheduled. */
    private const WORKED = ['present' => 1.0, 'wfh' => 1.0, 'on_duty' => 1.0, 'field_duty' => 1.0, 'half_day' => 0.5];

    private const NOT_SCHEDULED = ['weekly_off', 'holiday', 'leave', 'unpaid_leave', 'not_processed'];

    public function __construct(private readonly RoleLens $lenses, private readonly ApprovalCenter $approvals) {}

    /**
     * Four or five headline tiles for the lens: value, what it means, where to act.
     *
     * @return list<array{key: string, label: string, value: string, tone: string, icon: string, url: ?string, ring?: array{value: float, total: float}}>
     */
    public function kpis(User $user, string $lens, ?Employee $me, array $work, ?array $balances): array
    {
        $tiles = [];
        $add = function (string $key, string $label, int|float|string|null $value, string $tone, string $icon, ?string $url = null, ?array $ring = null) use (&$tiles) {
            if ($value === null) {
                return;
            }
            $tiles[] = array_filter(compact('key', 'label', 'tone', 'icon', 'url', 'ring') + ['value' => is_float($value) ? rtrim(rtrim(number_format($value, 1), '0'), '.') : (string) $value], fn ($v) => $v !== null);
        };
        $u = fn (callable $f) => rescue($f, null, false);
        $attention = $work['needs_attention']->count();
        $approvals = $this->approvals->count($user);
        $reports = $me ? $me->directReports()->currentlyEffective()->pluck('employee_id') : collect();
        $today = now()->toDateString();
        $onLeave = fn ($ids) => LeaveRequest::query()->when($ids !== null, fn ($q) => $q->whereIn('employee_id', $ids))->where('status', 'approved')
            ->whereDate('from_date', '<=', $today)->whereDate('to_date', '>=', $today)->distinct()->count('employee_id');

        switch ($lens) {
            case RoleLens::EXECUTIVE:
                $m = app(WorkforceMetrics::class)->all(['headcount', 'joiners_30d', 'exits_30d', 'attrition_rate', 'on_leave_today'], $user);
                $v = fn (string $k) => ($m[$k]['restricted'] ?? false) ? null : $m[$k]['value'];
                $add('headcount', 'People today', $v('headcount'), 'indigo', 'heroicon-o-users', $u(fn () => WorkforceCommandCentre::canAccess() ? WorkforceCommandCentre::getUrl() : null));
                $add('joiners', 'Joined in 30 days', $v('joiners_30d'), 'emerald', 'heroicon-o-user-plus');
                $add('exits', 'Left in 30 days', $v('exits_30d'), 'rose', 'heroicon-o-arrow-right-start-on-rectangle');
                $add('attrition', 'Attrition, 12 months', $v('attrition_rate') !== null ? round((float) $v('attrition_rate'), 1).'%' : null, 'amber', 'heroicon-o-arrow-trending-down');
                $add('on_leave', 'On leave today', $v('on_leave_today'), 'sky', 'heroicon-o-paper-airplane');
                break;
            case RoleLens::HR:
            case RoleLens::HR_ADMIN:
                $add('attention', 'Need your attention', $attention, 'rose', 'heroicon-o-exclamation-triangle', $u(fn () => MyWork::getUrl()));
                if ($user->hasPermission('employee.view')) {
                    $add('joining', 'Joining in 14 days', Employee::query()->whereIn('lifecycle_state', [LifecycleState::PreEmployee->value, LifecycleState::Preboarding->value])
                        ->whereBetween('expected_joining_date', [now()->startOfDay(), now()->addDays(14)->endOfDay()])->count(), 'emerald', 'heroicon-o-user-plus',
                        $u(fn () => OnboardingPlanResource::getUrl('index')));
                }
                if ($user->hasPermission('leave.view')) {
                    $add('on_leave', 'On leave today', $onLeave(null), 'sky', 'heroicon-o-paper-airplane', $u(fn () => LeaveCalendar::canAccess() ? LeaveCalendar::getUrl() : null));
                }
                $add('approvals', 'Approvals pending', $approvals, 'amber', 'heroicon-o-check-badge', $u(fn () => Approvals::canAccess() ? Approvals::getUrl() : null));
                if ($user->hasPermission('servicedesk.agent') || $user->hasPermission('servicedesk.view')) {
                    $add('requests', 'Open HR requests', app(CaseAccess::class)->visible(Ticket::query(), $user)->whereIn('tickets.status', Ticket::OPEN)->count(), 'violet', 'heroicon-o-lifebuoy',
                        $u(fn () => TicketResource::getUrl('index')));
                }
                break;
            case RoleLens::MANAGER:
                $add('attention', 'Need attention', $attention, 'rose', 'heroicon-o-exclamation-triangle', $u(fn () => MyWork::getUrl()));
                if ($reports->isNotEmpty()) {
                    $away = $onLeave($reports);
                    $add('team_in', 'Team in today', max(0, $reports->count() - $away), 'emerald', 'heroicon-o-user-group', $u(fn () => MyTeam::canAccess() ? MyTeam::getUrl() : null));
                    $add('team_away', 'Team on leave', $away, 'sky', 'heroicon-o-paper-airplane', $u(fn () => LeaveCalendar::canAccess() ? LeaveCalendar::getUrl() : null));
                }
                $add('approvals', 'Approvals pending', $approvals, 'amber', 'heroicon-o-check-badge', $u(fn () => Approvals::canAccess() ? Approvals::getUrl() : null));
                break;
            default:
                $add('attention', 'Need attention', $attention, 'rose', 'heroicon-o-exclamation-triangle', $u(fn () => MyWork::getUrl()));
                $add('waiting', 'Requests open', $work['waiting']->count(), 'sky', 'heroicon-o-arrow-path', $u(fn () => MyWork::getUrl(['tab' => 'waiting'])));
                if ($me) {
                    $add('learning', 'Learning open', LearningEnrolment::query()->where('employee_id', $me->id)->whereIn('status', LearningEnrolment::OPEN)->count(), 'violet', 'heroicon-o-academic-cap',
                        $u(fn () => MyLearning::canAccess() ? MyLearning::getUrl() : null));
                    $add('present', 'Days worked', $this->workedDays($me), 'emerald', 'heroicon-o-calendar-days');
                }
                if ($approvals > 0) {
                    $add('approvals', 'Approvals pending', $approvals, 'amber', 'heroicon-o-check-badge', $u(fn () => Approvals::getUrl()));
                }
        }

        // The leave ring: the person's main leave type, as available / entitlement this year.
        if ($balances !== null && $balances !== [] && in_array($lens, [RoleLens::EMPLOYEE, RoleLens::MANAGER], true)) {
            $main = collect($balances)->sortByDesc('total')->first();
            $tiles[] = ['key' => 'balance', 'label' => $main['name'], 'value' => rtrim(rtrim(number_format($main['available'], 1), '0'), '.'),
                'tone' => 'indigo', 'icon' => 'heroicon-o-sun', 'url' => null, 'ring' => ['value' => (float) $main['available'], 'total' => max(0.0, (float) $main['total'])]];
        }

        return array_slice($tiles, 0, 5);
    }

    /**
     * Your day: what is actually on the calendar in PeopleOS today (one-on-ones, training sessions,
     * approved leave, things due). No invented meetings.
     *
     * @return list<array{time: string, title: string, detail: ?string, action: ?string, url: ?string, tone: string}>
     */
    public function day(User $user, ?Employee $me, array $work): array
    {
        $items = [];
        $start = now()->startOfDay();
        $end = now()->endOfDay();
        if ($me !== null) {
            OneOnOne::query()->with(['employee.person', 'manager.person'])->where(fn ($q) => $q->where('employee_id', $me->id)->orWhere('manager_id', $me->id))
                ->whereBetween('scheduled_at', [$start, $end])->where('status', 'scheduled')->orderBy('scheduled_at')->limit(5)->get()
                ->each(function (OneOnOne $o) use (&$items, $me) {
                    $other = (int) $o->manager_id === (int) $me->id ? $o->employee : $o->manager;
                    $items[] = ['time' => $o->scheduled_at->format('H:i'), 'title' => 'One-on-one with '.($other?->display_name ?? '—'), 'detail' => Str::limit((string) $o->agenda, 60) ?: 'Agenda and notes in one place',
                        'action' => 'Open', 'url' => rescue(fn () => OneOnOneResource::getUrl('index'), null, false), 'tone' => 'violet'];
                });
            TrainingSessionAttendee::query()->with('session')->where('employee_id', $me->id)->whereNotIn('status', ['cancelled', 'waitlisted'])
                ->whereHas('session', fn ($q) => $q->whereBetween('starts_at', [$start, $end]))->limit(3)->get()
                ->each(function (TrainingSessionAttendee $a) use (&$items) {
                    $s = $a->session;
                    $items[] = ['time' => $s->starts_at->format('H:i'), 'title' => $s->title, 'detail' => ucfirst((string) $s->mode).($s->venue ? ' · '.$s->venue : ''),
                        'action' => $s->meeting_url ? 'Join' : 'View', 'url' => $s->meeting_url ?: rescue(fn () => TrainingSessionResource::getUrl('index'), null, false), 'tone' => 'teal'];
                });
            $leave = LeaveRequest::query()->with('leaveType')->where('employee_id', $me->id)->where('status', 'approved')->whereDate('from_date', '<=', $start)->whereDate('to_date', '>=', $start)->first();
            if ($leave) {
                $items[] = ['time' => 'All day', 'title' => 'You are on '.Str::lower($leave->leaveType?->name ?? 'leave'), 'detail' => 'Until '.$leave->to_date->format('D, d M'), 'action' => null, 'url' => null, 'tone' => 'sky'];
            }
        }
        foreach ($work['today'] as $row) {
            if (count($items) >= 6) {
                break;
            }
            $items[] = ['time' => $row['due'] && $row['due']->isToday() ? $row['due']->format('H:i') : 'Today', 'title' => $row['title'], 'detail' => $row['domain'].($row['detail'] ? ' · '.$row['detail'] : ''),
                'action' => $row['approval_id'] ? 'Review' : ($row['url'] ? 'Open' : null), 'url' => $row['url'], 'tone' => $row['kind'] === 'approval' ? 'amber' : 'indigo', 'approval_id' => $row['approval_id']];
        }
        usort($items, fn ($a, $b) => strcmp(str_pad($a['time'], 7, '0', STR_PAD_LEFT), str_pad($b['time'], 7, '0', STR_PAD_LEFT)));

        return $items;
    }

    /** @return array<string, mixed>|null */
    public function teamPulse(User $user, ?Employee $manager): ?array
    {
        if ($manager === null) {
            return null;
        }
        $reports = $manager->directReports()->currentlyEffective()->pluck('employee_id');
        if ($reports->isEmpty()) {
            return null;
        }
        $today = now()->toDateString();
        $people = Employee::query()->with('person')->whereKey($reports)->get();
        $away = LeaveRequest::query()->whereIn('employee_id', $reports)->where('status', 'approved')->whereDate('from_date', '<=', $today)->whereDate('to_date', '>=', $today)->pluck('employee_id')->unique();
        $attention = LeaveRequest::query()->whereIn('employee_id', $reports)->where('status', 'pending')->count()
            + LearningEnrolment::query()->whereIn('employee_id', $reports)->where('status', 'overdue')->count();
        $reviews = AppraisalReview::query()->where('reviewer_id', $manager->id)->where('status', 'pending')->count();

        return [
            'size' => $people->count(),
            'attention' => $attention,
            'away' => $away->count(),
            'reviews' => $reviews,
            'people' => $people->take(7)->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->display_name, 'away' => $away->contains($e->id)])->all(),
            'more' => max(0, $people->count() - 7),
            'url' => MyTeam::canAccess() ? MyTeam::getUrl() : null,
        ];
    }

    /**
     * Key insights for the viewer's scope (their team when they lead one and may see team attendance and
     * learning; otherwise themselves).
     *
     * @return array{scope: string, attendance: ?array, learning: ?array}|null
     */
    public function insights(User $user, ?Employee $me, string $lens): ?array
    {
        if ($me === null && ! in_array($lens, [RoleLens::HR, RoleLens::HR_ADMIN], true)) {
            return null;
        }
        $team = $me ? $me->directReports()->currentlyEffective()->pluck('employee_id') : collect();
        $useTeam = $team->isNotEmpty() && $lens !== RoleLens::EMPLOYEE;
        $ids = $useTeam ? $team : collect([$me?->id])->filter();
        if ($ids->isEmpty()) {
            return null;
        }
        $mayAttendance = $useTeam ? $user->hasPermission('attendance.view') : true;
        $mayLearning = $useTeam ? ($user->hasPermission('learning.team') || $user->hasPermission('learning.view')) : true;

        $attendance = null;
        if ($mayAttendance) {
            $months = [];
            for ($i = 4; $i >= 0; $i--) {
                $m = now()->subMonthsNoOverflow($i)->startOfMonth();
                $rows = AttendanceRecord::query()->whereIn('employee_id', $ids)->whereBetween('date', [$m->toDateString(), $m->copy()->endOfMonth()->toDateString()])
                    ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
                $scheduled = $rows->except(self::NOT_SCHEDULED)->sum();
                $worked = collect(self::WORKED)->sum(fn ($w, $s) => $w * (int) ($rows[$s] ?? 0));
                $months[] = ['label' => $m->format('M'), 'value' => $scheduled > 0 ? round($worked / $scheduled * 100) : null];
            }
            $values = array_values(array_filter(array_column($months, 'value'), fn ($v) => $v !== null));
            $attendance = $values === [] ? null : ['months' => $months, 'current' => end($values), 'previous' => count($values) > 1 ? $values[count($values) - 2] : null];
        }

        $learning = null;
        if ($mayLearning) {
            $rows = LearningEnrolment::query()->whereIn('employee_id', $ids)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
            $done = (int) ($rows['completed'] ?? 0);
            $progress = (int) ($rows['in_progress'] ?? 0);
            $notStarted = (int) collect(['assigned', 'enrolled', 'approved', 'overdue'])->sum(fn ($s) => $rows[$s] ?? 0);
            $total = $done + $progress + $notStarted;
            $learning = $total === 0 ? null : ['completed' => $done, 'in_progress' => $progress, 'not_started' => $notStarted, 'total' => $total, 'rate' => round($done / $total * 100)];
        }

        return $attendance === null && $learning === null ? null : ['scope' => $useTeam ? 'Your team' : 'You', 'attendance' => $attendance, 'learning' => $learning];
    }

    /** @return list<array{title: string, detail: ?string, when: ?string, url: ?string, icon: string, tone: string}> */
    public function companyPulse(?Employee $me): array
    {
        if ($me === null) {
            return [];
        }
        $url = AnnouncementsFeed::canAccess() ? AnnouncementsFeed::getUrl() : null;

        return app(Communications::class)->feedFor($me)->take(4)->map(fn ($a) => [
            'title' => $a->title,
            'detail' => Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags(Str::markdown((string) $a->body)))), 70),
            'when' => $a->published_at?->diffForHumans(),
            'url' => $url,
            'icon' => match ($a->type) {
                'policy' => 'heroicon-o-scale', 'instruction' => 'heroicon-o-clipboard-document-check', 'newsletter' => 'heroicon-o-newspaper', default => 'heroicon-o-megaphone'
            },
            'tone' => match ($a->priority) {
                'critical' => 'rose', 'high' => 'amber', default => 'violet'
            },
        ])->values()->all();
    }

    /** Organisation figures for the banner, only those the viewer may see. @return list<array{label: string, value: string}> */
    public function banner(User $user): array
    {
        $out = [];
        try {
            if ($user->hasPermission('employee.view') || $user->hasPermission('analytics.view')) {
                $out[] = ['label' => 'People', 'value' => number_format(app(WorkforceMetrics::class)->headcount())];
            }
            if ($user->hasPermission('organisation.view') || $user->hasPermission('employee.view')) {
                $out[] = ['label' => 'Locations', 'value' => (string) Location::query()->count()];
                $out[] = ['label' => 'Departments', 'value' => (string) Department::query()->count()];
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $out;
    }

    public function tenantName(): ?string
    {
        return app(TenantContext::class)->current()?->name;
    }

    public function peopleUrl(): ?string
    {
        return People::canAccess() ? People::getUrl() : null;
    }

    private function workedDays(Employee $me): ?float
    {
        $rows = AttendanceRecord::query()->where('employee_id', $me->id)->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->toDateString()])
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        if ($rows->isEmpty()) {
            return null;
        }

        return (float) collect(self::WORKED)->sum(fn ($w, $s) => $w * (int) ($rows[$s] ?? 0));
    }
}
