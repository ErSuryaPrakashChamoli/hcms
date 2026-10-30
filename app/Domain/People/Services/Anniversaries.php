<?php

namespace App\Domain\People\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Events\EmployeeReminderDue;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\People\Models\BirthdayWish;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * HROne setup parity HP-9a (Engage → Celebration): work and wedding anniversaries, beside birthdays (Birthdays).
 *
 * - Each kind is off until the organisation switches it on (celebration.{kind}.enabled).
 * - A work anniversary falls on the joining date's day and month, from the first full year of service. A wedding
 *   anniversary is the day and month the person records (no year); colleagues see it only when the person shares it.
 * - The same people are celebrated as for birthdays (working here now), on the tenant's own date; 29 February is
 *   celebrated on 28 February in other years.
 * - Visibility follows each kind's visible_to (organisation | company | team); celebration.manage sees everyone in scope.
 * - On the day: the greeting to the employee, a nudge to the people around them (announce_to), the day's list to the
 *   holders of notify_roles, and employee.{kind} for the tenant's own notification rules and workflows.
 */
final class Anniversaries
{
    public const KINDS = ['work_anniversary' => 'Work anniversary', 'wedding_anniversary' => 'Wedding anniversary'];

    public const PLURAL = ['work_anniversary' => 'Work anniversaries', 'wedding_anniversary' => 'Wedding anniversaries'];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AccessScopes $scopes,
        private readonly Notifier $notifier,
        private readonly Birthdays $birthdays,
    ) {}

    public function setting(string $kind, string $key): mixed
    {
        self::assertKind($kind);

        return $this->settings->get("celebration.{$kind}.{$key}");
    }

    public function enabled(string $kind): bool
    {
        return isset(self::KINDS[$kind]) && (bool) $this->setting($kind, 'enabled');
    }

    /** @return list<string> the kinds the organisation celebrates */
    public function enabledKinds(): array
    {
        return array_values(array_filter(array_keys(self::KINDS), fn (string $kind) => $this->enabled($kind)));
    }

    /** Completed years of service on $day (work anniversaries). */
    public static function years(Employee $employee, CarbonInterface $day): int
    {
        return $employee->joining_date === null ? 0 : $day->year - $employee->joining_date->year;
    }

    public static function yearsLabel(int $years): string
    {
        return $years === 1 ? '1 year' : "{$years} years";
    }

    /** The date of $employee's anniversary of $kind in $year (null when none is recorded). */
    public static function dateIn(string $kind, Employee $employee, int $year): ?CarbonInterface
    {
        $key = match ($kind) {
            'work_anniversary' => $employee->joining_date ? $employee->joining_date->month * 100 + $employee->joining_date->day : null,
            'wedding_anniversary' => ($m = $employee->person?->wedding_anniversary_month) && ($d = $employee->person?->wedding_anniversary_day) ? $m * 100 + $d : null,
            default => null,
        };

        return $key === null ? null : Birthdays::dateIn($key, $year);
    }

    /** @return Collection<int, Employee> celebrating $kind on $day, as $viewer may see them (null = everyone: system runs). */
    public function on(string $kind, CarbonInterface $day, ?User $viewer = null): Collection
    {
        return $this->sorted($kind, $this->onDays($kind, $this->query($kind, $viewer), [$day], $day->year)->get(), $day->year);
    }

    /** @return Collection<int, Employee> celebrating $kind in $month of $year, by day. */
    public function inMonth(string $kind, int $year, int $month, ?User $viewer = null): Collection
    {
        $query = $this->query($kind, $viewer);
        $kind === 'work_anniversary'
            ? $query->whereMonth('joining_date', $month)->whereYear('joining_date', '<', $year)
            : $query->whereHas('person', fn (Builder $q) => $q->where('wedding_anniversary_month', $month));

        return $this->sorted($kind, $query->get(), $year);
    }

    /** @return Collection<int, Employee> celebrating $kind in the $days days after $from (not $from itself), by date. */
    public function upcoming(string $kind, CarbonInterface $from, int $days, ?User $viewer = null): Collection
    {
        $dates = [];
        for ($d = $from->copy()->addDay(); $d->lte($from->copy()->addDays($days)); $d->addDay()) {
            $dates[] = $d->copy();
        }

        return $this->onDays($kind, $this->query($kind, $viewer), $dates, null)->get()
            ->filter(fn (Employee $e) => $kind !== 'work_anniversary' || self::years($e, $this->next($kind, $e, $from)) >= 1)
            ->sortBy(fn (Employee $e) => $this->next($kind, $e, $from)->timestamp)->values();
    }

    /** The next date (after $from) $employee celebrates $kind. */
    public function next(string $kind, Employee $employee, CarbonInterface $from): CarbonInterface
    {
        $date = self::dateIn($kind, $employee, $from->year);

        return $date->lte($from) ? self::dateIn($kind, $employee, $from->year + 1) : $date;
    }

    /**
     * The celebrants of $kind $viewer may see (null: a system run sees everyone): celebration managers see their whole
     * access scope; colleagues see them within the kind's visible_to, and a wedding anniversary only when shared.
     *
     * @return Builder<Employee>
     */
    public function query(string $kind, ?User $viewer = null): Builder
    {
        self::assertKind($kind);
        $query = Employee::query()->with(['person', 'user', 'currentManager', 'currentPosition.designation', 'currentPosition.department'])
            ->whereIn('lifecycle_state', Birthdays::STATES);
        $kind === 'work_anniversary'
            ? $query->whereNotNull('joining_date')
            : $query->whereHas('person', fn (Builder $q) => $q->whereNotNull('wedding_anniversary_month')->whereNotNull('wedding_anniversary_day'));

        if ($viewer === null) {
            return $query;
        }
        if ($this->scopes->isScoped($viewer)) {
            $query = ($viewer->getKey() === auth()->id() ? $query->withoutGlobalScope(AccessScope::class) : $query);
            $query = $this->scopes->constrainEmployees($query, $viewer);
        }
        if ($this->birthdays->canSeeAll($viewer)) {
            return $query;
        }
        if (! $viewer->hasPermission('celebration.view')) {
            return $query->whereRaw('1 = 0');
        }
        if ($kind === 'wedding_anniversary') {
            $query->whereHas('person', fn (Builder $q) => $q->where('wedding_anniversary_shared', true));
        }
        $me = app(RoleLens::class)->employee($viewer);

        return match ($this->setting($kind, 'visible_to')) {
            'company' => ($company = $me?->currentPosition?->company_id) === null
                ? $query->whereKey($me?->id ?? 0)
                : $query->whereHas('currentPosition', fn (Builder $q) => $q->where('company_id', $company)),
            'team' => $query->whereKey($me === null ? [] : $this->birthdays->team($me)),
            default => $query,
        };
    }

    /**
     * $sender wishes $employee on their anniversary of $kind: only while the kind is celebrated, on the day, never
     * oneself, once a year, and only someone whose anniversary the sender can see.
     */
    public function wish(string $kind, User $sender, Employee $employee, string $message): BirthdayWish
    {
        if (! $this->enabled($kind)) {
            throw new RuntimeException('The organisation does not celebrate '.strtolower(self::PLURAL[$kind] ?? 'this occasion').'.');
        }
        $message = trim(strip_tags($message));
        if ($message === '') {
            throw new RuntimeException('Write a message to send.');
        }
        if (mb_strlen($message) > Birthdays::MESSAGE_MAX) {
            throw new RuntimeException('Keep the message to '.Birthdays::MESSAGE_MAX.' characters.');
        }
        $employee->loadMissing(['person', 'user']);
        if ((int) $employee->user_id === (int) $sender->getKey()) {
            throw new RuntimeException('You cannot wish yourself.');
        }
        $today = $this->birthdays->today();
        if (! $this->onDays($kind, $this->query($kind, $sender), [$today], $today->year)->whereKey($employee->getKey())->exists()) {
            throw new RuntimeException($this->query($kind, $sender)->whereKey($employee->getKey())->exists()
                ? 'Wishes can be sent on the anniversary itself.' : 'This anniversary is not shared with you.');
        }
        $label = strtolower(self::KINDS[$kind]);
        if (BirthdayWish::query()->where('employee_id', $employee->id)->where('sender_user_id', $sender->getKey())->where('year', $today->year)->where('kind', $kind)->exists()) {
            throw new RuntimeException('You have already wished '.$employee->person->display_name.' this year.');
        }

        try {
            $wish = BirthdayWish::create([
                'employee_id' => $employee->id,
                'sender_user_id' => $sender->getKey(),
                'sender_employee_id' => app(RoleLens::class)->employee($sender)?->id,
                'kind' => $kind,
                'year' => $today->year,
                'message' => $message,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('You have already wished '.$employee->person->display_name.' this year.');
        }

        $recipient = $employee->user;
        if ($recipient !== null && $recipient->isActive()) {
            $this->notifier->send([$recipient], ['in_app'], $sender->name.' wished you a happy '.$label, $message, "celebration.{$kind}.wish", $wish);
        }

        return $wish;
    }

    /** @return Collection<int, BirthdayWish> the $kind wishes $employee received in $year, newest first. */
    public function wishesFor(string $kind, Employee $employee, int $year): Collection
    {
        return BirthdayWish::query()->with(['sender', 'senderEmployee.person'])->where('employee_id', $employee->id)->where('year', $year)->where('kind', $kind)->latest('id')->get();
    }

    /** @return list<int> recipients $sender already wished for $kind this year */
    public function wishedBy(string $kind, User $sender, int $year): array
    {
        return BirthdayWish::query()->where('sender_user_id', $sender->getKey())->where('year', $year)->where('kind', $kind)->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
    }

    /** The kind's greeting as $employee receives it ({name}, {years}, {organisation}). */
    public function greeting(string $kind, Employee $employee, CarbonInterface $day): string
    {
        return $this->birthdays->render((string) $this->setting($kind, 'greeting_message'), (string) $employee->person?->first_name, ['{years}' => self::yearsLabel(self::years($employee, $day))]);
    }

    /**
     * The day's notices for every celebrated kind: the employee's greeting, the nudge to the people around them (never
     * for a wedding anniversary kept private), the day's list to the holders of notify_roles, and employee.{kind}.
     *
     * @return array<string, array{celebrants: int, greetings: int, nudges: int, lists: int}>
     */
    public function sendDayNotices(CarbonInterface $day): array
    {
        $result = [];
        foreach ($this->enabledKinds() as $kind) {
            $counts = ['celebrants' => 0, 'greetings' => 0, 'nudges' => 0, 'lists' => 0];
            $celebrants = $this->on($kind, $day);
            $counts['celebrants'] = $celebrants->count();
            $label = strtolower(self::KINDS[$kind]);
            foreach ($celebrants as $employee) {
                $years = self::years($employee, $day);
                EmployeeReminderDue::dispatch($employee, "employee.{$kind}", $kind === 'work_anniversary' ? ['years' => $years] : []);

                $user = $employee->user;
                if ($user !== null && $user->isActive()) {
                    $this->notifier->send([$user], ['in_app'], 'Happy '.$label.', '.$employee->person->first_name.'!', $this->greeting($kind, $employee, $day), "celebration.{$kind}.greeting", $employee);
                    $counts['greetings']++;
                }

                if (($kind === 'wedding_anniversary' && ! $employee->person->wedding_anniversary_shared) || $this->setting($kind, 'announce_to') === 'none') {
                    continue;
                }
                $around = $this->setting($kind, 'announce_to') === 'manager' ? array_filter([(int) $employee->currentManager?->manager_id]) : $this->birthdays->team($employee);
                $users = User::query()->where('status', UserStatus::Active)->whereKeyNot($employee->user_id ?? 0)
                    ->whereIn('id', Employee::query()->whereKey($around)->whereKeyNot($employee->id)->whereIn('lifecycle_state', Birthdays::STATES)->whereNotNull('user_id')->select('user_id'))
                    ->get();
                $what = $kind === 'work_anniversary' ? 'work anniversary today ('.self::yearsLabel($years).')' : 'wedding anniversary today';
                $this->notifier->send($users, ['in_app'], 'It’s '.$employee->person->display_name.'’s '.$what, 'Send '.$employee->person->first_name.' your wishes from Celebrations.', "celebration.{$kind}.today", $employee);
                $counts['nudges'] += $users->count();
            }

            foreach ($celebrants->isEmpty() ? [] : $this->birthdays->roleHolders((array) $this->setting($kind, 'notify_roles')) as $user) {
                $people = $this->on($kind, $day, $user)->reject(fn (Employee $e) => (int) $e->user_id === (int) $user->id)->values();
                if ($people->isEmpty()) {
                    continue;
                }
                $subject = $people->count() === 1 ? self::KINDS[$kind].' today: '.$people->first()->person->display_name : self::PLURAL[$kind].' today: '.$people->count().' people';
                $body = $people->take(15)->map(fn (Employee $e) => $e->person->display_name.' ('.$e->employee_code.')'.($kind === 'work_anniversary' ? ', '.self::yearsLabel(self::years($e, $day)) : ''))->implode('; ').'.'
                    .($people->count() > 15 ? ' … and '.($people->count() - 15).' more on the Celebrations page.' : '');
                $this->notifier->send([$user], ['in_app'], $subject, $body, "celebration.{$kind}.today");
                $counts['lists']++;
            }
            $result[$kind] = $counts;
        }

        return $result;
    }

    /**
     * @param  Builder<Employee>  $query
     * @param  list<CarbonInterface>  $days
     * @return Builder<Employee>
     */
    private function onDays(string $kind, Builder $query, array $days, ?int $year): Builder
    {
        $pairs = [];
        foreach ($days as $day) {
            foreach (Birthdays::keysOn($day) as $key) {
                $pairs[] = [intdiv($key, 100), $key % 100];
            }
        }
        if ($kind === 'work_anniversary') {
            $query->where(function (Builder $q) use ($pairs) {
                foreach ($pairs as [$month, $day]) {
                    $q->orWhere(fn (Builder $w) => $w->whereMonth('joining_date', $month)->whereDay('joining_date', $day));
                }
            });

            return $year === null ? $query : $query->whereYear('joining_date', '<', $year);
        }

        return $query->whereHas('person', function (Builder $q) use ($pairs) {
            $q->where(function (Builder $q) use ($pairs) {
                foreach ($pairs as [$month, $day]) {
                    $q->orWhere(fn (Builder $w) => $w->where('wedding_anniversary_month', $month)->where('wedding_anniversary_day', $day));
                }
            });
        });
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, Employee>
     */
    private function sorted(string $kind, Collection $employees, int $year): Collection
    {
        return $employees->sortBy([
            fn (Employee $a, Employee $b) => self::dateIn($kind, $a, $year) <=> self::dateIn($kind, $b, $year),
            fn (Employee $a, Employee $b) => strcmp((string) $a->person->display_name, (string) $b->person->display_name),
        ])->values();
    }

    private static function assertKind(string $kind): void
    {
        if (! isset(self::KINDS[$kind])) {
            throw new RuntimeException("Unknown celebration [{$kind}].");
        }
    }
}
