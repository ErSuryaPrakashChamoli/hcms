<?php

namespace App\Domain\People\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Lifecycle\Events\EmployeeReminderDue;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\People\Models\BirthdayWish;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Birthdays: who is celebrated on a day or in a month, who may see it, wishes, and the notices the daily run sends.
 *
 * - The day comes from Person::birthday_key: the celebrated birthday when recorded (papers sometimes carry another
 *   date), else the date of birth. Colleagues only ever see the day and month, never the year or the age.
 * - Holders of celebration.manage see everyone in their access scope, including people who keep their birthday to
 *   themselves; they receive the monthly list and the day's list. Holders of celebration.view see the birthdays the
 *   tenant shares with colleagues (celebration.birthdays.visible_to), minus anyone who opted out, and may wish them on the day.
 * - "Today" is the tenant's own date (its timezone), so a birthday starts at the tenant's midnight.
 * - 29 February birthdays are celebrated on 28 February in other years.
 */
final class Birthdays
{
    /** Lifecycle states whose people are celebrated (working here now). */
    public const STATES = [LifecycleState::Onboarding, LifecycleState::Joined, LifecycleState::Probation, LifecycleState::Confirmed, LifecycleState::Active, LifecycleState::OnLeave, LifecycleState::NoticePeriod];

    public const VISIBLE_TO = ['organisation' => 'Everyone in the organisation', 'company' => 'People in the same company', 'team' => 'Their manager, team-mates and direct reports'];

    public const ANNOUNCE_TO = ['none' => 'Nobody (the Birthdays page only)', 'manager' => 'Their line manager', 'team' => 'Their manager, team-mates and direct reports'];

    public const MESSAGE_MAX = 500;

    /** People listed in one email, and in one in-app notification; the rest are on the Birthdays page. */
    private const LIST_LIMIT = 60;

    private const INLINE_LIMIT = 15;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AccessScopes $scopes,
        private readonly Notifier $notifier,
        private readonly TenantContext $tenants,
    ) {}

    public function setting(string $key): mixed
    {
        return $this->settings->get("celebration.birthdays.{$key}");
    }

    /** Now on the tenant's own clock (its timezone). */
    public function now(): CarbonInterface
    {
        return Carbon::now($this->tenants->current()?->timezone ?: config('app.timezone'));
    }

    public function today(): CarbonInterface
    {
        return $this->now()->startOfDay();
    }

    /** @return list<int> the birthday keys celebrated on $day (28 February also celebrates 29 February outside leap years). */
    public static function keysOn(CarbonInterface $day): array
    {
        $keys = [$day->month * 100 + $day->day];
        if ($day->month === 2 && $day->day === 28 && ! $day->isLeapYear()) {
            $keys[] = 229;
        }

        return $keys;
    }

    /** The date a birthday key falls on in $year. */
    public static function dateIn(int $key, int $year): CarbonInterface
    {
        $month = intdiv($key, 100);
        $day = $key % 100;
        if ($key === 229 && ! Carbon::create($year)->isLeapYear()) {
            $day = 28;
        }

        return Carbon::create($year, $month, $day)->startOfDay();
    }

    /** @return Collection<int, Employee> celebrated on $day, as $viewer may see them (null = everyone: system runs). */
    public function on(CarbonInterface $day, ?User $viewer = null): Collection
    {
        return $this->sorted($this->query($viewer)->whereHas('person', fn (Builder $q) => $q->whereIn('birthday_key', self::keysOn($day)))->get(), $day->year);
    }

    /** How many people $viewer sees celebrating on $day (one count query). */
    public function countOn(CarbonInterface $day, User $viewer): int
    {
        return $this->query($viewer)->whereHas('person', fn (Builder $q) => $q->whereIn('birthday_key', self::keysOn($day)))->count();
    }

    /** @return Collection<int, Employee> celebrated in $month of $year, by day. */
    public function inMonth(int $year, int $month, ?User $viewer = null): Collection
    {
        return $this->sorted($this->query($viewer)->whereHas('person', fn (Builder $q) => $q->whereBetween('birthday_key', [$month * 100 + 1, $month * 100 + 31]))->get(), $year);
    }

    /** @return Collection<int, Employee> celebrated in the $days days after $from (not $from itself), by date. */
    public function upcoming(CarbonInterface $from, int $days, ?User $viewer = null): Collection
    {
        $keys = [];
        for ($d = $from->copy()->addDay(); $d->lte($from->copy()->addDays($days)); $d->addDay()) {
            array_push($keys, ...self::keysOn($d));
        }

        return $this->query($viewer)->whereHas('person', fn (Builder $q) => $q->whereIn('birthday_key', $keys))->get()
            ->sortBy(fn (Employee $e) => self::next($e->person->birthday_key, $from)->timestamp)->values();
    }

    /** The next date (after $from) a birthday key is celebrated. */
    public static function next(int $key, CarbonInterface $from): CarbonInterface
    {
        $date = self::dateIn($key, $from->year);

        return $date->lte($from) ? self::dateIn($key, $from->year + 1) : $date;
    }

    public function canSeeAll(User $viewer): bool
    {
        return $viewer->hasPermission('celebration.manage');
    }

    /**
     * The celebrated employees $viewer may see (null: a system run sees everyone). Managers of celebrations see their
     * whole access scope; colleagues see shared birthdays within birthdays.visible_to.
     *
     * @return Builder<Employee>
     */
    public function query(?User $viewer = null): Builder
    {
        $query = Employee::query()->with(['person', 'user', 'currentManager', 'currentPosition.designation', 'currentPosition.department'])
            ->whereIn('lifecycle_state', self::STATES);

        if ($viewer === null) {
            return $query;
        }
        if ($this->scopes->isScoped($viewer)) {
            // The global scope follows the signed-in user; constrain for $viewer explicitly (once) so the answer is right
            // in a system run too.
            $query = ($viewer->getKey() === auth()->id() ? $query->withoutGlobalScope(AccessScope::class) : $query);
            $query = $this->scopes->constrainEmployees($query, $viewer);
        }
        if ($this->canSeeAll($viewer)) {
            return $query;
        }
        if (! $viewer->hasPermission('celebration.view')) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereHas('person', fn (Builder $q) => $q->where('birthday_shared', true));
        $me = app(RoleLens::class)->employee($viewer);

        return match ($this->setting('visible_to')) {
            'company' => ($company = $me?->currentPosition?->company_id) === null
                ? $query->whereKey($me?->id ?? 0)
                : $query->whereHas('currentPosition', fn (Builder $q) => $q->where('company_id', $company)),
            'team' => $query->whereKey($me === null ? [] : $this->team($me)),
            default => $query,
        };
    }

    /** @return list<int> the employee, their manager, everyone sharing that manager, and their direct reports. */
    public function team(Employee $employee): array
    {
        $ids = [$employee->id];
        $manager = $employee->currentManager?->manager_id;
        if ($manager !== null) {
            $ids[] = (int) $manager;
            array_push($ids, ...ReportingRelationship::query()->where('manager_id', $manager)->where('is_primary', true)->currentlyEffective()->pluck('employee_id')->map(fn ($id) => (int) $id)->all());
        }
        array_push($ids, ...$employee->directReports()->currentlyEffective()->pluck('employee_id')->map(fn ($id) => (int) $id)->all());

        return array_values(array_unique($ids));
    }

    /**
     * $sender wishes $employee a happy birthday: on the day only, never oneself, once a year, and only someone whose
     * birthday the sender can see. The recipient gets an in-app notification with the message.
     */
    public function wish(User $sender, Employee $employee, string $message): BirthdayWish
    {
        $message = trim(strip_tags($message));
        if ($message === '') {
            throw new RuntimeException('Write a message to send.');
        }
        if (mb_strlen($message) > self::MESSAGE_MAX) {
            throw new RuntimeException('Keep the message to '.self::MESSAGE_MAX.' characters.');
        }
        $employee->loadMissing(['person', 'user']);
        if ((int) $employee->user_id === (int) $sender->getKey()) {
            throw new RuntimeException('You cannot wish yourself.');
        }
        if (! $this->query($sender)->whereKey($employee->getKey())->exists()) {
            throw new RuntimeException('This birthday is not shared with you.');
        }
        $today = $this->today();
        if (! in_array($employee->person->birthday_key, self::keysOn($today), true)) {
            throw new RuntimeException('Wishes can be sent on the birthday itself.');
        }
        if (BirthdayWish::query()->where('employee_id', $employee->id)->where('sender_user_id', $sender->getKey())->where('year', $today->year)->exists()) {
            throw new RuntimeException('You have already wished '.$employee->person->display_name.' this year.');
        }

        try {
            $wish = BirthdayWish::create([
                'employee_id' => $employee->id,
                'sender_user_id' => $sender->getKey(),
                'sender_employee_id' => app(RoleLens::class)->employee($sender)?->id,
                'year' => $today->year,
                'message' => $message,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('You have already wished '.$employee->person->display_name.' this year.');
        }

        $recipient = $employee->user;
        if ($recipient !== null && $recipient->isActive()) {
            $this->notifier->send([$recipient], ['in_app'], $sender->name.' wished you a happy birthday', $message, 'celebration.birthday.wish', $wish);
        }

        return $wish;
    }

    /** @return Collection<int, BirthdayWish> the wishes $employee received in $year, newest first. */
    public function wishesFor(Employee $employee, int $year): Collection
    {
        return BirthdayWish::query()->with(['sender', 'senderEmployee.person'])->where('employee_id', $employee->id)->where('year', $year)->latest('id')->get();
    }

    /** @return list<int> recipients $sender already wished this year */
    public function wishedBy(User $sender, int $year): array
    {
        return BirthdayWish::query()->where('sender_user_id', $sender->getKey())->where('year', $year)->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The month whose list is due: the list goes out digest_lead_days before the month starts (0 = on the 1st). A run
     * missed on that day catches up within the month's first week; later the list is not sent (the page has it).
     */
    public function digestMonthDue(CarbonInterface $today): ?CarbonInterface
    {
        $ahead = $today->copy()->addDays(max(0, (int) $this->setting('digest_lead_days')));

        return $ahead->day <= 7 ? $ahead->startOfMonth() : null;
    }

    /** @return Collection<int, User> active users who manage celebrations (the monthly and daily lists go to them). */
    public function managers(): Collection
    {
        return User::forCurrentTenant()->where('status', UserStatus::Active)
            ->whereHas('roles.permissions', fn (Builder $q) => $q->where('key', 'celebration.manage'))
            ->get()->filter(fn (User $u) => $u->hasPermission('celebration.manage'))->values();
    }

    /** The monthly list: to every celebration manager, the birthdays of $month in their access scope. Returns the number sent. */
    public function sendMonthlyList(CarbonInterface $month): int
    {
        $sent = 0;
        foreach ($this->managers() as $user) {
            $people = $this->inMonth($month->year, $month->month, $user);
            if ($people->isEmpty()) {
                continue;
            }
            $subject = 'Birthdays in '.$month->format('F Y').': '.$people->count().' '.($people->count() === 1 ? 'person' : 'people');
            $this->sendList($user, $this->channels(), $subject, $people, $month->year, true, 'celebration.birthday.month');
            $sent++;
        }

        return $sent;
    }

    /**
     * The day's notices for everyone celebrated on $day: the employee's built-in greeting, a nudge to the people around
     * them (birthdays.announce_to; never for people who keep their birthday to themselves), the day's list to celebration
     * managers, and employee.birthday for the tenant's own notification rules and workflows.
     *
     * @return array{celebrants: int, greetings: int, nudges: int, lists: int}
     */
    public function sendDayNotices(CarbonInterface $day): array
    {
        $counts = ['celebrants' => 0, 'greetings' => 0, 'nudges' => 0, 'lists' => 0];
        $celebrants = $this->on($day);
        $counts['celebrants'] = $celebrants->count();
        if ($celebrants->isEmpty()) {
            return $counts;
        }

        $organisation = $this->settings->get('branding.display_name') ?: $this->tenants->current()?->name;
        foreach ($celebrants as $employee) {
            EmployeeReminderDue::dispatch($employee, 'employee.birthday', ['day' => $employee->person->birthdayLabel()]);

            $user = $employee->user;
            if ($this->setting('greeting_enabled') && $user !== null && $user->isActive()) {
                $body = strtr((string) $this->setting('greeting_message'), ['{name}' => $employee->person->first_name, '{organisation}' => (string) $organisation]);
                $this->notifier->send([$user], $this->channels(), 'Happy birthday, '.$employee->person->first_name.'!', $body, 'celebration.birthday.greeting', $employee);
                $counts['greetings']++;
            }

            if (! $employee->person->birthday_shared || $this->setting('announce_to') === 'none') {
                continue;
            }
            $around = $this->setting('announce_to') === 'manager' ? array_filter([(int) $employee->currentManager?->manager_id]) : $this->team($employee);
            $users = User::query()->where('status', UserStatus::Active)->whereKeyNot($employee->user_id ?? 0)
                ->whereIn('id', Employee::query()->whereKey($around)->whereKeyNot($employee->id)->whereIn('lifecycle_state', self::STATES)->whereNotNull('user_id')->select('user_id'))
                ->get();
            $name = $employee->person->display_name;
            $this->notifier->send($users, ['in_app'], 'It’s '.$name.'’s birthday today', 'Send '.$employee->person->first_name.' your wishes from Birthdays.', 'celebration.birthday.today', $employee);
            $counts['nudges'] += $users->count();
        }

        if ($this->setting('notify_admins_on_day')) {
            foreach ($this->managers() as $user) {
                $people = $this->on($day, $user);
                if ($people->isEmpty()) {
                    continue;
                }
                $subject = $people->count() === 1 ? 'Birthday today: '.$people->first()->person->display_name : 'Birthdays today: '.$people->count().' people';
                $this->sendList($user, ['in_app'], $subject, $people, $day->year, false, 'celebration.birthday.today');
                $counts['lists']++;
            }
        }

        return $counts;
    }

    /** @return list<string> */
    private function channels(): array
    {
        return $this->setting('email') ? ['in_app', 'email'] : ['in_app'];
    }

    /**
     * A list of people to one celebration manager. In-app shows one paragraph (line breaks are not kept there), so it
     * gets a compact list; email gets one person per line with their role and department.
     *
     * @param  list<string>  $channels
     * @param  Collection<int, Employee>  $people
     */
    private function sendList(User $user, array $channels, string $subject, Collection $people, int $year, bool $withDay, string $event): void
    {
        $entry = fn (Employee $e, bool $full) => ($withDay ? self::dateIn($e->person->birthday_key, $year)->format('D j M').' — ' : '')
            .$e->person->display_name.' ('.$e->employee_code.($e->person->birthday_shared ? '' : ', private').')'
            .($full && ($where = collect([$e->currentPosition?->designation?->name, $e->currentPosition?->department?->name])->filter()->implode(', ')) !== '' ? ', '.$where : '');
        $more = fn (int $shown) => $people->count() > $shown ? ['… and '.($people->count() - $shown).' more on the Birthdays page.'] : [];

        $compact = $people->take(self::INLINE_LIMIT)->map(fn (Employee $e) => $entry($e, false))->implode('; ');
        $this->notifier->send([$user], ['in_app'], $subject, implode(' ', [$compact.'.', ...$more(self::INLINE_LIMIT)]), $event);

        if (in_array('email', $channels, true)) {
            $lines = $people->take(self::LIST_LIMIT)->map(fn (Employee $e) => $entry($e, true))->all();
            $this->notifier->send([$user], ['email'], $subject, implode("\n", [...$lines, ...$more(self::LIST_LIMIT)])."\n\nPrivate: the person keeps their birthday from colleagues.", $event);
        }
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, Employee>
     */
    private function sorted(Collection $employees, int $year): Collection
    {
        return $employees->sortBy([
            fn (Employee $a, Employee $b) => self::dateIn($a->person->birthday_key, $year) <=> self::dateIn($b->person->birthday_key, $year),
            fn (Employee $a, Employee $b) => strcmp((string) $a->person->display_name, (string) $b->person->display_name),
        ])->values();
    }
}
