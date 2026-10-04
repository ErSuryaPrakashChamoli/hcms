<?php

namespace App\Domain\Experience\Services;

use App\Domain\Analytics\Services\WorkforceMetrics;
use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\LeaveEntitlements;
use App\Domain\Leave\Services\LeaveYear;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\LeaveCalendar;
use App\Filament\Pages\MyTeam;
use App\Filament\Pages\PayrollControlRoom;
use App\Filament\Resources\Payslips\PayslipResource;
use Carbon\CarbonInterface;
use Throwable;

/**
 * UX: the role-aware Home (§12). It answers "what matters to me now" for the viewer's lens and explains
 * why each item needs attention. It composes existing read models (WorkInbox, QuickActions, ChangeFeed,
 * WorkforceMetrics, NeedsAttention) and existing domain queries; every panel is gated by the same
 * permissions as the screen it links to.
 */
final class HomeComposer
{
    /**
     * UX.16: one Home, composed per experience. The main column leads with what that person is here for; personal
     * items stay one compact "For you" block away for everyone who is also an employee.
     */
    public const MAIN = [
        'employee' => ['personal', 'decisions', 'attention', 'day', 'requests', 'changes'],
        'manager' => ['decisions', 'team', 'day', 'changes', 'foryou'],
        'hr' => ['operations', 'decisions', 'changes', 'foryou'],
        'payroll' => ['payroll', 'decisions', 'changes', 'foryou'],
        'executive' => ['workforce', 'changes', 'decisions', 'foryou'],
        'admin' => ['governance', 'decisions', 'foryou'],
    ];

    public const SIDE = [
        'employee' => ['intelligence', 'circle', 'momentum', 'actions'],
        'manager' => ['intelligence', 'team_pulse', 'actions'],
        'hr' => ['intelligence', 'actions'],
        'payroll' => ['intelligence', 'actions'],
        'executive' => ['intelligence', 'actions'],
        'admin' => ['intelligence', 'actions'],
    ];

    public function __construct(
        private readonly RoleLens $lenses,
        private readonly WorkInbox $inbox,
        private readonly QuickActions $actions,
        private readonly ExperiencePreferences $preferences,
    ) {}

    /** @return array<string, mixed> */
    public function for(User $user, ?string $lens = null): array
    {
        $lenses = $this->lenses->lenses($user);
        $prefs = $this->preferences->for($user);
        $primary = $this->lenses->primary($user, $lens ?? $prefs['lens'] ?? null);
        $employee = $this->lenses->employee($user);
        $work = $this->inbox->for($user);
        $hidden = $prefs['home_hidden'] ?? [];
        $blocks = app(HomeBlocks::class);
        $me = $employee !== null && in_array($primary, [RoleLens::EMPLOYEE, RoleLens::MANAGER], true) ? $this->me($user, $employee) : null;
        // Balances feed the employee and manager views only (the leave ring); other experiences skip the leave queries.
        $balances = $me['balances'] ?? null;
        $safe = function (callable $f, mixed $fallback = null) {
            try {
                return $f();
            } catch (Throwable $e) {
                report($e);

                return $fallback;
            }
        };

        // UX.15: decisions, changes since the last visit and the one-sentence brief of the day.
        $approvals = app(ApprovalCenter::class);
        $decisionCount = $safe(fn () => $approvals->count($user), 0);
        $decisions = $decisionCount > 0 ? $safe(fn () => $approvals->pending($user)->take(4)->values(), collect()) : collect();
        // UX.15.20: more are waiting than the queue lists (a source reached its scan limit): shown as "200+".
        $decisionMore = $decisionCount > 0 && $safe(fn () => $approvals->capped($user), false);
        $experience = RoleLens::experienceOf($primary);
        $signals = app(RoleSignals::class);
        $changes = $experience !== 'admin' && ! in_array('feed', $hidden, true)
            ? $safe(fn () => app(ChangeFeed::class)->since($user, app(ExperiencePreferences::class)->markHomeVisit($user)), null) : null;
        $operations = $experience === 'hr' ? $safe(fn () => $signals->operations($user), []) : null;
        $workforce = $experience === 'executive' ? $safe(fn () => $signals->workforce($user)) : null;
        $governance = $experience === 'admin' ? $safe(fn () => $signals->governance($user), ['attention' => [], 'facts' => []]) : null;

        return [
            'brief' => $this->brief($experience, $work, $decisionCount, $changes, $decisionMore, $operations, $governance),
            'brief_text' => $workforce['headline'] ?? null,
            'decisions' => $decisions,
            'decision_count' => $decisionCount,
            'decision_more' => $decisionMore,
            'changes' => $changes,
            'circle' => $employee !== null && $primary === RoleLens::EMPLOYEE ? $safe(fn () => $this->circle($user, $employee), []) : [],
            'lens' => $primary,
            'lenses' => $lenses,
            'experience' => $experience,
            'experiences' => $this->lenses->experiences($user),
            'main' => self::MAIN[$experience],
            'side' => self::SIDE[$experience],
            'employee' => $employee,
            'focus' => $this->focus($work),
            'next' => $this->nextActions($work, $prefs['snoozed'] ?? []),
            'personal' => $employee !== null ? $safe(fn () => $signals->personal($user), []) : [],
            'team_signals' => $experience === 'manager' ? $safe(fn () => $signals->team($user), []) : [],
            'requests' => $employee !== null && in_array($experience, ['employee', 'manager'], true) ? $work['waiting']->take(5)->values()->all() : [],
            'kpis' => $experience === 'admin' ? [] : $safe(fn () => $blocks->kpis($user, $primary, $employee, $work, $balances), []),
            'actions' => $this->featuredActions($user, $primary, $employee !== null),
            'me' => $me,
            'day' => in_array($primary, [RoleLens::EMPLOYEE, RoleLens::MANAGER], true) ? $safe(fn () => $blocks->day($user, $employee, $work), []) : null,
            'journey' => $journey = ($employee !== null && $primary === RoleLens::EMPLOYEE ? $safe(fn () => app(JourneyMap::class)->for($user, $employee)) : null),
            'journey_nodes' => $journey ? $this->journeyNodes($journey) : [],
            'pulse_team' => in_array($primary, [RoleLens::MANAGER, RoleLens::EMPLOYEE], true) && $this->lenses->has($user, RoleLens::MANAGER) ? $safe(fn () => $blocks->teamPulse($user, $employee)) : null,
            'tenant' => $blocks->tenantName(),
            'team' => $primary === RoleLens::MANAGER || ($primary === RoleLens::EMPLOYEE && $this->lenses->has($user, RoleLens::MANAGER)) ? $this->team($user, $employee) : null,
            'operations' => $operations,
            'workforce' => $workforce,
            'governance' => $governance,
            'payroll' => $experience === 'payroll' ? $this->payroll($user) : null,
            'feed' => $changes !== null,
            'hidden' => $hidden,
        ];
    }

    /**
     * The one-sentence brief of the day, from real counts only: decisions waiting, things needing attention,
     * things due today and changes since the last visit. Each part is [number, text] so the number can be
     * emphasised; an empty list means nothing needs the person.
     *
     * @return list<array{0: int|string, 1: string}>
     */
    private function brief(string $experience, array $work, int $decisions, ?array $changes, bool $more, ?array $operations, ?array $governance): array
    {
        $parts = [];
        // UX.16: each experience leads with its own work (HR operations, governance); executives lead with the workforce headline.
        if ($experience === 'hr' && ($ops = count($operations ?? [])) > 0) {
            $parts[] = [$ops, $ops === 1 ? 'operational item needs attention' : 'operational items need attention'];
        }
        if ($experience === 'admin' && ($gov = count($governance['attention'] ?? [])) > 0) {
            $parts[] = [$gov, $gov === 1 ? 'governance item needs attention' : 'governance items need attention'];
        }
        if ($decisions > 0) {
            $parts[] = [$more ? $decisions.'+' : $decisions, $decisions === 1 && ! $more ? 'decision is waiting for you' : 'decisions are waiting for you'];
        }
        if (in_array($experience, ['employee', 'manager', 'payroll'], true)) {
            $attention = $work['needs_attention']->where('kind', '!=', 'approval')->count();
            if ($attention > 0) {
                $parts[] = [$attention, $attention === 1 ? 'thing needs your attention' : 'things need your attention'];
            }
            $due = $work['today']->where('kind', '!=', 'approval')->count();
            if ($due > 0) {
                $parts[] = [$due, $due === 1 ? 'thing is due today' : 'things are due today'];
            }
        }
        if ($experience !== 'executive' && ($changes['count'] ?? 0) > 0) {
            $parts[] = [$changes['count'], ($changes['count'] === 1 ? 'thing changed' : 'things changed').($changes['since'] ? ' since your last visit' : ' this week')];
        }

        return $parts;
    }

    /** The employee's own people: their manager and anyone reporting to them (PeopleVisibility's circle). @return list<array{id: int, name: string, role: string}> */
    private function circle(User $user, Employee $me): array
    {
        $ids = array_values(array_diff(app(PeopleVisibility::class)->circle($user), [$me->id]));
        if ($ids === []) {
            return [];
        }
        $managerId = $me->currentManager?->manager_id;

        return Employee::query()->with(['person', 'currentPosition.designation'])->whereKey($ids)->get()
            ->sortByDesc(fn (Employee $e) => $e->id === $managerId)
            ->map(fn (Employee $e) => ['id' => $e->id, 'name' => (string) $e->display_name, 'role' => $e->id === $managerId ? 'Your manager' : ($e->currentPosition?->designation?->name ?? 'Reports to you')])
            ->values()->all();
    }

    /** Four nodes for the compact journey: joined, now, growth, next. @return list<array{label: string, sub: ?string, state: string}> */
    private function journeyNodes(array $journey): array
    {
        $stages = collect($journey['stages'] ?? []);
        if ($stages->isEmpty()) {
            return [];
        }
        $joined = $stages->firstWhere('key', 'joined');
        $current = $stages->firstWhere('state', 'current');
        $growth = $stages->firstWhere('key', 'growth');
        $next = $stages->first(fn ($s) => $s['state'] === 'upcoming' && $s['key'] !== 'growth');

        return collect([
            $joined ? ['label' => 'Joined', 'sub' => $joined['date']?->format('M Y'), 'state' => 'done'] : null,
            $current ? ['label' => $current['label'], 'sub' => $current['date'] ? $current['date']->diffForHumans(now(), ['parts' => 1, 'syntax' => CarbonInterface::DIFF_ABSOLUTE]) : 'Now', 'state' => 'current'] : null,
            $growth ? ['label' => 'Growth', 'sub' => in_array($growth['state'], ['done', 'current'], true) ? ($growth['summary'] ?? 'In progress') : 'Ahead', 'state' => $growth['state'] === 'upcoming' ? 'upcoming' : 'done'] : null,
            $next ? ['label' => $next['label'], 'sub' => 'Next', 'state' => 'upcoming'] : null,
        ])->filter()->unique('label')->values()->all();
    }

    /**
     * Up to three "next best actions": the most urgent items with one primary verb, skipping anything the
     * person snoozed ("Not now") until tomorrow.
     *
     * @return list<array<string, mixed>>
     */
    private function nextActions(array $work, array $snoozed): array
    {
        $tones = ['approval' => 'amber', 'task' => 'violet', 'attention' => 'rose', 'request' => 'sky'];
        $icons = ['approval' => 'heroicon-o-check-badge', 'task' => 'heroicon-o-clipboard-document-check', 'attention' => 'heroicon-o-exclamation-triangle', 'request' => 'heroicon-o-paper-airplane'];

        return $work['needs_attention']->merge($work['today'])->merge($work['upcoming'])
            ->reject(fn ($row) => isset($snoozed['home:'.$row['key']]))
            ->filter(fn ($row) => $row['approval_id'] || $row['url'])
            ->take(3)
            ->map(fn ($row) => $row + [
                'tone' => $row['severity'] === 'danger' ? 'rose' : ($tones[$row['kind']] ?? 'indigo'),
                'icon' => $icons[$row['kind']] ?? 'heroicon-o-sparkles',
                'verb' => $row['approval_id'] ? 'Review' : ($row['kind'] === 'task' ? 'Continue' : 'Open'),
            ])->values()->all();
    }

    /** @return array{summary: string, items: list<array<string, mixed>>, counts: array<string, int>} */
    private function focus(array $work): array
    {
        $attention = $work['needs_attention'];
        $today = $work['today'];
        $approvals = $attention->merge($today)->where('kind', 'approval')->count();
        $parts = [];
        if ($approvals > 0) {
            $parts[] = $approvals.' '.($approvals === 1 ? 'decision' : 'decisions').' waiting for you';
        }
        $urgent = $attention->where('kind', '!=', 'approval')->count();
        if ($urgent > 0) {
            $parts[] = $urgent.' '.($urgent === 1 ? 'thing needs' : 'things need').' attention';
        }
        $due = $today->where('kind', '!=', 'approval')->count();
        if ($due > 0) {
            $parts[] = $due.' due today';
        }
        $summary = $parts === [] ? 'Nothing needs you right now.' : ucfirst(implode(', ', $parts)).'.';

        return [
            'summary' => $summary,
            'items' => $attention->merge($today)->take(4)->values()->all(),
            'counts' => ['attention' => $attention->count(), 'today' => $today->count(), 'upcoming' => $work['upcoming']->count(), 'waiting' => $work['waiting']->count()],
        ];
    }

    private function featuredActions(User $user, string $lens, bool $isEmployee): array
    {
        $own = $this->actions->forLens($user, $lens, 4);
        if ($isEmployee && $lens !== RoleLens::EMPLOYEE) {
            $own = [...$own, ...$this->actions->forLens($user, RoleLens::EMPLOYEE, 2)];
        }
        if (count($own) < 4 && $lens === RoleLens::EMPLOYEE && $this->lenses->has($user, RoleLens::MANAGER)) {
            $own = [...$own, ...$this->actions->forLens($user, RoleLens::MANAGER, 2)];
        }

        return array_slice(collect($own)->unique('key')->values()->all(), 0, 6);
    }

    /** @return array<string, mixed> */
    private function me(User $user, Employee $employee): array
    {
        $today = now()->toDateString();
        $punches = AttendancePunch::query()->where('employee_id', $employee->id)->whereDate('punched_at', $today)->orderBy('punched_at')->get();
        $record = AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', $today)->first();
        $last = $punches->last();
        $balances = [];
        if ($user->can('leave.apply')) {
            try {
                $period = app(LeaveYear::class)->periodFor(now());
                foreach (array_keys(app(LeaveEntitlements::class)->for($employee)) as $code) {
                    $type = LeaveType::query()->where('code', $code)->first();
                    if ($type !== null) {
                        $b = app(LeaveBalances::class)->balance($employee, $type, $period);
                        $balances[] = ['code' => $type->code, 'name' => $type->name, 'available' => $b->available(), 'total' => (float) $b->opening + (float) $b->accrued + (float) $b->adjusted];
                    }
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
        $payslip = Payslip::query()->where('employee_id', $employee->id)->latest('generated_at')->first();
        $nextLeave = LeaveRequest::query()->with('leaveType')->where('employee_id', $employee->id)->whereIn('status', ['approved', 'pending'])->whereDate('to_date', '>=', $today)->orderBy('from_date')->first();

        return [
            'checked_in' => $last !== null && $last->direction !== 'out',
            'first_in' => $punches->first()?->punched_at,
            'last_out' => $last?->direction === 'out' ? $last->punched_at : null,
            'can_punch' => $user->can('attendance.regularise') || $user->can('leave.apply'),
            'status' => $record ? config("peopleos.attendance.statuses.{$record->status}", $record->status) : null,
            'balances' => array_slice($balances, 0, 4),
            'payslip' => $payslip && $user->can('view', $payslip) ? ['label' => $payslip->period?->label() ?? $payslip->generated_at?->format('M Y'), 'url' => PayslipResource::getUrl('view', ['record' => $payslip])] : null,
            'next_leave' => $nextLeave ? ['label' => ($nextLeave->leaveType?->name ?? 'Leave').' · '.$nextLeave->from_date->format('d M').($nextLeave->to_date->isSameDay($nextLeave->from_date) ? '' : ' → '.$nextLeave->to_date->format('d M')), 'status' => $nextLeave->status] : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function team(User $user, ?Employee $manager): ?array
    {
        if ($manager === null) {
            return null;
        }
        $reports = $manager->directReports()->currentlyEffective()->pluck('employee_id');
        if ($reports->isEmpty()) {
            return null;
        }
        $today = now()->toDateString();
        $away = LeaveRequest::query()->with(['employee.person', 'leaveType'])->whereIn('employee_id', $reports)->where('status', 'approved')
            ->whereDate('from_date', '<=', $today)->whereDate('to_date', '>=', $today)->get();
        $people = Employee::query()->with('person')->whereKey($reports)->get();

        return [
            'size' => $people->count(),
            'people' => $people->take(8)->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->display_name, 'away' => $away->contains('employee_id', $e->id)])->all(),
            'away' => $away->map(fn ($r) => ['name' => $r->employee?->display_name, 'type' => $r->leaveType?->name, 'until' => $r->to_date->format('d M')])->all(),
            'pending' => LeaveRequest::query()->whereIn('employee_id', $reports)->where('status', 'pending')->count()
                + AttendanceRegularisation::query()->whereIn('employee_id', $reports)->where('status', 'pending')->count(),
            'links' => array_filter([
                'approvals' => Approvals::canAccess() ? Approvals::getUrl() : null,
                'calendar' => LeaveCalendar::canAccess() ? LeaveCalendar::getUrl() : null,
                'team' => MyTeam::canAccess() ? MyTeam::getUrl() : null,
            ]),
        ];
    }

    /** @return array<string, mixed>|null */
    private function payroll(User $user): ?array
    {
        if (! $user->hasPermission('payroll.view') && ! $user->hasPermission('payroll.calculate')) {
            return null;
        }
        $run = PayrollRun::query()->with('period')->latest('id')->first();

        return [
            'run' => $run ? ['label' => $run->period?->label() ?? 'Run #'.$run->id, 'status' => config("peopleos.payroll.run_statuses.{$run->status}", $run->status), 'exceptions' => (int) $run->exception_count,
                'employees' => (int) $run->total('employees'), 'net' => $run->total('net_pay')] : null,
            'url' => PayrollControlRoom::canAccess() ? PayrollControlRoom::getUrl() : null,
        ];
    }
}
