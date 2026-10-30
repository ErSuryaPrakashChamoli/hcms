<?php

namespace App\Domain\Experience\Services;

use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\TravelRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\People\Services\Anniversaries;
use App\Domain\People\Services\Birthdays;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Models\Initiative;
use App\Domain\Performance\Models\OneOnOne;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Recognition\Models\Recognition;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * HROne parity CA-9 (Access rights → Calendar access): the Team calendar — one month of the events of the people a viewer
 * looks after: a manager their direct reports today ("My team"), HR everyone in their access scope ("Organisation").
 *
 * Read only, and it never widens anything: every event is read through its own domain under the viewer's access scope
 * (the signed-in person only) — birthdays and anniversaries as the celebrations settings allow, every other event only
 * when the viewer may open the record it comes from (its policy). The organisation chooses the events per audience
 * (calendar.manager_events / calendar.hr_events, default all); a stored value that is not a list shows nothing, and an
 * event kind that fails is left out (reported). Reasons, notes and leave reasons are never shown.
 */
final class TeamCalendar
{
    public const AUDIENCES = ['team' => 'My team', 'organisation' => 'Organisation'];

    private const SETTINGS = ['team' => 'calendar.manager_events', 'organisation' => 'calendar.hr_events'];

    /** At most this many records per event kind and month. */
    public const CAP = 500;

    public function __construct(private readonly RoleLens $lenses, private readonly TenantContext $tenants) {}

    /** @return array<string, string> the audiences open to the signed-in $viewer */
    public function audiences(User $viewer): array
    {
        if (! $this->signedIn($viewer)) {
            return [];
        }
        $out = [];
        if ($this->lenses->has($viewer, RoleLens::MANAGER)) {
            $out['team'] = self::AUDIENCES['team'];
        }
        if ($this->lenses->has($viewer, RoleLens::HR)) {
            $out['organisation'] = self::AUDIENCES['organisation'];
        }

        return $out;
    }

    /** @return array<string, string> the event kinds the organisation shows to this audience */
    public function kinds(User $viewer, string $audience): array
    {
        if (! array_key_exists($audience, $this->audiences($viewer))) {
            return [];
        }
        $all = (array) config('peopleos.calendar.events', []);
        $chosen = app(SettingsRepository::class)->get(self::SETTINGS[$audience], array_keys($all));
        if (! is_array($chosen)) {
            return [];
        }

        return array_intersect_key($all, array_flip(array_map('strval', array_filter($chosen, 'is_scalar'))));
    }

    /**
     * The month's events by day (Y-m-d => list of events), for the kinds asked (all the audience may see when none).
     *
     * @param  list<string>  $only
     * @return array<string, list<array{kind: string, label: string, name: string, employee_id: int, detail: ?string}>>
     */
    public function events(User $viewer, string $audience, int $year, int $month, array $only = []): array
    {
        $kinds = $this->kinds($viewer, $audience);
        if ($only !== []) {
            $kinds = array_intersect_key($kinds, array_flip($only));
        }
        if ($kinds === [] || $month < 1 || $month > 12) {
            return [];
        }
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $end = $start->endOfMonth();
        $team = $audience === 'team' ? $this->team($viewer) : null;

        $events = [];
        foreach (array_keys($kinds) as $kind) {
            try {
                foreach ($this->read($kind, $viewer, $team, $start, $end) as $event) {
                    $events[] = ['kind' => $kind, 'label' => $kinds[$kind]] + $event;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
        $order = array_flip(array_keys((array) config('peopleos.calendar.events', [])));
        usort($events, fn (array $a, array $b) => [$a['date'], $order[$a['kind']] ?? 99, $a['name']] <=> [$b['date'], $order[$b['kind']] ?? 99, $b['name']]);

        $days = [];
        foreach ($events as $event) {
            $date = $event['date'];
            unset($event['date']);
            $days[$date][] = $event;
        }

        return $days;
    }

    /**
     * @param  list<int>|null  $team
     * @return list<array{date: string, name: string, employee_id: int, detail: ?string}>
     */
    private function read(string $kind, User $viewer, ?array $team, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $inTeam = fn (Builder $q, string $column = 'employee_id') => $team === null ? $q : $q->whereIn($column, $team);
        // Date columns: the upper bound carries a time, so the last day matches whether the driver stores a bare date or not.
        $days = [$start->toDateString(), $end->toDateString().' 23:59:59'];

        return match ($kind) {
            'birthday' => $this->celebrants(app(Birthdays::class)->inMonth($start->year, $start->month, $viewer), $team,
                fn (Employee $e) => Birthdays::dateIn((int) $e->person->birthday_key, $start->year), fn () => null),
            'work_anniversary', 'wedding_anniversary' => app(Anniversaries::class)->enabled($kind)
                ? $this->celebrants(app(Anniversaries::class)->inMonth($kind, $start->year, $start->month, $viewer), $team,
                    fn (Employee $e) => Anniversaries::dateIn($kind, $e, $start->year),
                    fn (Employee $e) => $kind === 'work_anniversary' ? Anniversaries::yearsLabel(Anniversaries::years($e, $start)) : null)
                : [],
            'confirmation' => $this->records($viewer, $inTeam(Employee::query()->with('person')->whereBetween('probation_end_date', $days)
                ->whereNotIn('lifecycle_state', ['exited', 'alumni', 'confirmed']), 'employees.id'), 'probation_end_date', fn () => 'Probation ends', self: true),
            'joining' => $this->records($viewer, $inTeam(Employee::query()->with('person')->where(fn (Builder $q) => $q
                ->whereBetween('joining_date', $days)
                ->orWhere(fn (Builder $q) => $q->whereNull('joining_date')->whereBetween('expected_joining_date', $days))), 'employees.id'),
                fn (Employee $e) => $e->joining_date ?? $e->expected_joining_date, fn (Employee $e) => $e->joining_date ? 'Joins' : 'Expected to join', self: true),
            'last_working_day' => $this->records($viewer, $inTeam(ExitCase::query()->with('employee.person')->whereBetween('last_working_day', $days)
                ->whereNotIn('status', ['withdrawn', 'cancelled', 'rejected'])), 'last_working_day', fn () => 'Last working day'),
            'document_expiry' => $this->records($viewer, $inTeam(EmployeeDocument::query()->with(['employee.person', 'documentType'])->whereBetween('expires_on', $days)
                ->where('status', '!=', 'archived')), 'expires_on', fn (EmployeeDocument $d) => ($d->documentType?->name ?? $d->title ?? 'Document').' expires'),
            'leave' => $this->records($viewer, $inTeam(LeaveRequest::query()->with(['employee.person', 'leaveType'])->whereIn('status', LeaveRequest::TAKEN)
                ->whereDate('from_date', '<=', $end->toDateString())->whereDate('to_date', '>=', $start->toDateString())),
                fn (LeaveRequest $r) => $r->from_date->lt($start) ? $start : $r->from_date,
                fn (LeaveRequest $r) => ($r->leaveType?->name ?? 'Leave').' · '.$r->from_date->format('j M').($r->to_date->equalTo($r->from_date) ? '' : ' – '.$r->to_date->format('j M'))),
            'overtime', 'comp_off' => $this->records($viewer, $inTeam(TimeOfficeRequest::query()->with('employee.person')->where('type', $kind)->where('status', 'approved')
                ->whereBetween('from_date', $days)), 'from_date',
                fn (TimeOfficeRequest $r) => $kind === 'overtime' ? ($r->minutes ? 'Overtime · '.intdiv((int) $r->minutes, 60).' h '.((int) $r->minutes % 60).' min' : 'Overtime') : 'Comp-off'),
            'one_on_one' => $this->records($viewer, $inTeam(OneOnOne::query()->with('employee.person')->where('status', '!=', 'cancelled')
                ->whereBetween('scheduled_at', [$start->toDateTimeString(), $end->toDateTimeString()])), 'scheduled_at',
                fn (OneOnOne $o) => 'One-on-one · '.$o->scheduled_at->format('H:i')),
            'pip_end' => $this->records($viewer, $inTeam(ImprovementPlan::query()->with('employee.person')->whereIn('status', ['active', 'extended', 'pending_outcome'])
                ->whereBetween('end_date', $days)), 'end_date', fn () => 'Improvement plan ends'),
            'initiative_due' => $this->records($viewer, $inTeam(Initiative::query()->with('employee.person')->whereIn('status', ['planned', 'in_progress'])
                ->whereBetween('due_date', $days)), 'due_date', fn (Initiative $i) => 'Initiative due · '.$i->title),
            'travel' => $this->records($viewer, $inTeam(TravelRequest::query()->with('employee.person')->whereIn('status', TravelRequest::APPROVED)
                ->whereDate('start_date', '<=', $end->toDateString())->whereDate('end_date', '>=', $start->toDateString())),
                fn (TravelRequest $t) => $t->start_date->lt($start) ? $start : $t->start_date,
                fn (TravelRequest $t) => 'Travel · '.$t->start_date->format('j M').($t->end_date->equalTo($t->start_date) ? '' : ' – '.$t->end_date->format('j M'))),
            'expense' => $this->records($viewer, $inTeam(ExpenseReceipt::query()->with('employee.person')->whereIn('status', ExpenseReceipt::COUNTING)
                ->whereBetween('expense_date', $days)), 'expense_date',
                fn (ExpenseReceipt $r) => ($r->getAttributes()['distance_km'] ?? null) ? 'Mileage claim · '.(float) $r->distance_km.' km' : 'Expense claim'),
            'badge' => $this->records($viewer, $inTeam(Recognition::query()->with(['employee.person', 'badge'])->whereNotNull('badge_id')->whereNull('withdrawn_at')
                ->whereBetween('created_at', [$start->toDateTimeString(), $end->toDateTimeString()])), 'created_at',
                fn (Recognition $r) => 'Badge received · '.($r->badge?->name ?? 'badge')),
            default => [],
        };
    }

    /**
     * Records the viewer may open (their policy), at most CAP per kind.
     *
     * @param  string|callable  $date  the date attribute, or a callable giving the date
     * @return list<array{date: string, name: string, employee_id: int, detail: ?string}>
     */
    private function records(User $viewer, Builder $query, string|callable $date, callable $detail, bool $self = false): array
    {
        $out = [];
        foreach ($query->limit(self::CAP)->get() as $record) {
            if (! $viewer->can('view', $record)) {
                continue;
            }
            $employee = $self ? $record : $record->employee;
            $day = is_string($date) ? $record->{$date} : $date($record);
            if ($employee === null || $day === null) {
                continue;
            }
            $out[] = ['date' => CarbonImmutable::parse($day)->toDateString(), 'name' => (string) ($employee->person?->display_name ?? $employee->employee_code),
                'employee_id' => (int) $employee->getKey(), 'detail' => $detail($record)];
        }

        return $out;
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  list<int>|null  $team
     * @return list<array{date: string, name: string, employee_id: int, detail: ?string}>
     */
    private function celebrants(Collection $employees, ?array $team, callable $date, callable $detail): array
    {
        return $employees->filter(fn (Employee $e) => $team === null || in_array((int) $e->getKey(), $team, true))
            ->take(self::CAP)
            ->map(fn (Employee $e) => ['date' => CarbonImmutable::parse($date($e))->toDateString(), 'name' => (string) ($e->person?->display_name ?? $e->employee_code),
                'employee_id' => (int) $e->getKey(), 'detail' => $detail($e)])
            ->values()->all();
    }

    /** @return list<int> the viewer's direct reports today (any reporting type, as the manager lens counts them) */
    private function team(User $viewer): array
    {
        $me = $this->lenses->employee($viewer);

        return $me === null ? [] : $me->directReports()->currentlyEffective()->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function signedIn(User $viewer): bool
    {
        return auth()->check() && (int) auth()->id() === (int) $viewer->getKey() && $this->tenants->has();
    }
}
