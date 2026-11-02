<?php

namespace App\Domain\Letters\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\TransferRequest;
use App\Domain\Engagement\Services\AudienceQuery;
use App\Domain\Exit\Services\RetirementDates;
use App\Domain\Experience\Services\ScreenAccess;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Letters\Jobs\RunLetterSchedule;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterSchedule;
use App\Domain\Letters\Models\LetterScheduleItem;
use App\Domain\Letters\Models\LetterSignatory;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Support\PolicyLetters;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Enums\ActiveStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * HROne parity WO-13 (§23.7.2 #2–5, 7, 8, 11): letter schedules.
 *
 * - save(): a schedule names a template (and up to four signatories, extra values), a trigger — one time (now, or at a date
 *   and time) or an employee event (joining, birthday, confirmation, resignation, leaving, retirement, work anniversary,
 *   absconding) N days before or after — and an audience: named employees, employees organisation-wise (the audience
 *   criteria announcements use, resolved inside the person's scope) or upcoming joiners. Saved for later use it does not
 *   run; active it runs. Needs letter.issue.
 * - run() (the queued job, from `peopleos:letters:tick` once a day or at the publish time): as the person who set it up —
 *   their access scope and rights — one letter per employee and occurrence through Letters::generate (process `schedule`),
 *   issued at once when the template needs no approval and the schedule says so, and e-mailed when it asks and e-mailing is
 *   switched on. Each employee is handled on its own; what happened is kept per employee and occurrence (never twice).
 *   A schedule whose owner can no longer issue letters, or whose template is no longer active, is paused with the reason.
 */
final class LetterSchedules
{
    public function __construct(private readonly Letters $letters, private readonly AudienceQuery $audiences) {}

    /** @param  array<string, mixed>  $data */
    public function save(array $data, User $actor, ?LetterSchedule $schedule = null): LetterSchedule
    {
        self::mayManage($actor);
        $clean = $this->validate($data, $actor);
        $schedule ??= new LetterSchedule(['created_by' => $actor->id]);
        if ($schedule->exists && in_array($schedule->status, ['completed', 'cancelled'], true)) {
            throw new RuntimeException('A completed or cancelled schedule cannot be changed; save a new one.');
        }
        // Editing keeps a paused schedule paused; "save for later use" parks it; otherwise it is active.
        if ($schedule->exists && $schedule->status === 'paused' && $clean['status'] === 'active') {
            $clean['status'] = 'paused';
        }
        $schedule->withAuditReason($schedule->exists ? 'Letter schedule changed' : 'Letter schedule created')->fill($clean)->save();
        if ($schedule->status === 'active') {
            $this->dispatchIfDue($schedule);
        }

        return $schedule->refresh();
    }

    /** Saved for later → active, or a paused schedule resumed. */
    public function activate(LetterSchedule $schedule, User $actor): LetterSchedule
    {
        self::mayManage($actor);
        if (! in_array($schedule->status, ['saved', 'paused'], true)) {
            throw new RuntimeException('Only a saved or paused schedule can be started.');
        }
        if ($schedule->trigger === 'one_time' && $schedule->run_at !== null && $schedule->run_at->isPast()) {
            throw new RuntimeException('The publish time has passed: change it first.');
        }
        $schedule->withAuditReason('Letter schedule started')->update(['status' => 'active', 'last_error' => null]);
        $this->dispatchIfDue($schedule);

        return $schedule;
    }

    public function pause(LetterSchedule $schedule, User $actor): LetterSchedule
    {
        self::mayManage($actor);
        if ($schedule->status !== 'active') {
            throw new RuntimeException('Only an active schedule can be paused.');
        }
        $schedule->withAuditReason('Letter schedule paused')->update(['status' => 'paused']);

        return $schedule;
    }

    public function cancel(LetterSchedule $schedule, User $actor, string $reason): LetterSchedule
    {
        self::mayManage($actor);
        if (in_array($schedule->status, ['completed', 'cancelled'], true)) {
            throw new RuntimeException('This schedule is already finished.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Say why the schedule is cancelled.');
        }
        $schedule->withAuditReason($reason)->update(['status' => 'cancelled']);

        return $schedule;
    }

    /** "Use" a saved schedule again: a copy saved for later use. */
    public function duplicate(LetterSchedule $schedule, User $actor): LetterSchedule
    {
        self::mayManage($actor);
        $copy = $schedule->replicate(['status', 'last_run_on', 'runs_count', 'letters_count', 'last_error', 'created_by']);
        $copy->fill(['name' => mb_substr('Copy of '.$schedule->name, 0, 255), 'status' => 'saved', 'created_by' => $actor->id, 'runs_count' => 0, 'letters_count' => 0]);
        if ($copy->trigger === 'one_time' && $copy->run_at !== null && $copy->run_at->isPast()) {
            $copy->run_at = null;
        }
        $copy->withAuditReason('Copied from letter schedule #'.$schedule->id)->save();

        return $copy;
    }

    /**
     * The daily tick: event schedules run now; one-time schedules due today are queued for their publish time.
     *
     * @return int schedules queued
     */
    public function dispatchDue(?CarbonInterface $now = null): int
    {
        $now = CarbonImmutable::parse($now ?? now());
        $count = 0;
        LetterSchedule::query()->where('status', 'active')
            ->where(fn (Builder $q) => $q->where('trigger', 'event')->orWhere(fn (Builder $o) => $o->where('trigger', 'one_time')->where(fn (Builder $w) => $w->whereNull('run_at')->orWhere('run_at', '<=', $now->endOfDay()))))
            ->orderBy('id')->each(function (LetterSchedule $schedule) use ($now, &$count) {
                $delay = $schedule->trigger === 'one_time' && $schedule->run_at !== null && $schedule->run_at->gt($now) ? $schedule->run_at : null;
                $delay ? RunLetterSchedule::dispatch($schedule->id)->delay($delay) : RunLetterSchedule::dispatch($schedule->id);
                $count++;
            });

        return $count;
    }

    /**
     * Runs one schedule now (the queued job).
     *
     * @return array{generated: int, issued: int, refused: int, failed: int, skipped: int}
     */
    public function run(LetterSchedule $schedule, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::parse($now ?? now());
        $counts = ['generated' => 0, 'issued' => 0, 'refused' => 0, 'failed' => 0, 'skipped' => 0];
        $schedule->refresh();
        if ($schedule->status !== 'active' || ($schedule->trigger === 'one_time' && $schedule->run_at !== null && $schedule->run_at->gt($now))) {
            return $counts;
        }
        $actor = $schedule->created_by ? User::query()->whereKey($schedule->created_by)->where('tenant_id', $schedule->tenant_id)->first() : null;
        if ($actor === null || $actor->status !== UserStatus::Active || ! $actor->hasPermission('letter.issue')) {
            $this->halt($schedule, 'The person who set up this schedule can no longer issue letters. Someone who can should copy it and start the copy.');

            return $counts;
        }
        $template = LetterTemplate::query()->where('status', 'active')->find($schedule->letter_template_id);
        if ($template === null) {
            $this->halt($schedule, 'The template is no longer active.');

            return $counts;
        }

        // One run of a schedule at a time (the publish-time job and the daily tick may meet).
        $lock = Cache::lock('letters:schedule:'.$schedule->id, 1800);
        if (! $lock->get()) {
            return $counts;
        }
        try {
            return $this->runLocked($schedule->refresh(), $actor, $template, $now, $counts);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{generated: int, issued: int, refused: int, failed: int, skipped: int}  $counts
     * @return array{generated: int, issued: int, refused: int, failed: int, skipped: int}
     */
    private function runLocked(LetterSchedule $schedule, User $actor, LetterTemplate $template, CarbonImmutable $now, array $counts): array
    {
        if ($schedule->status !== 'active') {
            return $counts;
        }
        $today = $now->startOfDay();
        $occurrence = $schedule->trigger === 'one_time' ? CarbonImmutable::parse($schedule->run_at ?? $schedule->created_at)->toDateString() : null;
        $max = max(1, (int) config('peopleos.letters.schedule_max_employees', 2000));
        $note = null;
        ScreenAccess::as($actor, function () use ($schedule, $actor, $template, $today, $occurrence, $max, &$counts, &$note) {
            [$signatory, $others] = $this->signatories($schedule);
            $employees = $this->employees($schedule, $actor, $today)->with('person')->orderBy('employees.id')->limit($max + 1)->get();
            if ($employees->count() > $max) {
                $employees = $employees->take($max);
                $note = "More than {$max} employees matched; the first {$max} were handled.";
            }
            foreach ($employees as $employee) {
                $date = $occurrence ?? $this->eventDate($schedule, $employee, $today);
                if ($date === null) {
                    continue;
                }
                if (LetterScheduleItem::query()->where('letter_schedule_id', $schedule->id)->where('employee_id', $employee->id)->whereDate('occurrence', $date)->exists()) {
                    $counts['skipped']++;

                    continue;
                }
                [$outcome, $letter, $message] = $this->one($schedule, $template, $employee, $actor, $signatory, $others);
                $counts[$outcome]++;
                if ($outcome === 'issued') {
                    $counts['generated']++;
                }
                $item = (new LetterScheduleItem(['letter_schedule_id' => $schedule->id, 'employee_id' => $employee->id, 'occurrence' => $date, 'letter_id' => $letter?->id,
                    'outcome' => $outcome, 'message' => $message !== null ? mb_substr($message, 0, 255) : null]))->withoutAuditing();
                rescue(fn () => $item->save(), report: true);
            }
        });

        $schedule->withoutAuditing()->update([
            'runs_count' => $schedule->runs_count + 1, 'letters_count' => $schedule->letters_count + $counts['generated'], 'last_run_on' => $today->toDateString(),
            'last_error' => $note, 'status' => $schedule->trigger === 'one_time' ? 'completed' : $schedule->status,
        ]);

        return $counts;
    }

    /**
     * HROne parity WO-13 (§23.7.1 #25–26): transfers approved ahead of their date get the transfer policy's
     * `letter_on_effect` letter on the effective date (looking back a week, once per transfer).
     */
    public function transferEffects(?CarbonInterface $today = null): int
    {
        $today = CarbonImmutable::parse($today ?? now())->startOfDay();
        $drafted = 0;
        TransferRequest::query()->with('employee.person', 'reason')->where('status', 'approved')
            ->whereDate('effective_date', '<=', $today->toDateString())->whereDate('effective_date', '>=', $today->subDays(7)->toDateString())
            ->orderBy('id')->each(function (TransferRequest $request) use (&$drafted) {
                if ($request->employee === null || $request->reviewed_at === null || $request->effective_date->lte($request->reviewed_at->copy()->endOfDay())) {
                    return; // took effect on approval: drafted then
                }
                $exists = Letter::query()->withoutGlobalScope(AccessScope::class)->where('process', 'transfer.letter_on_effect')
                    ->where('source_type', $request->getMorphClass())->where('source_id', $request->id)->exists();
                if ($exists) {
                    return;
                }
                $template = PolicyLetters::template('transfer.letter_on_effect', $request->employee, $request->effective_date);
                $reviewer = $request->reviewed_by ? User::query()->find($request->reviewed_by) : null;
                if ($template !== null && PolicyLetters::draft($template->id, $request->employee, $reviewer, $request, 'transfer.letter_on_effect', PolicyLetters::transferValues($request)) !== null) {
                    $drafted++;
                }
            });

        return $drafted;
    }

    /**
     * The employees a schedule reaches (inside the signed-in person's scope: run() signs in as the schedule's owner).
     *
     * @return Builder<Employee>
     */
    public function employees(LetterSchedule $schedule, User $actor, CarbonInterface $on): Builder
    {
        $query = match ($schedule->audience) {
            'criteria' => Employee::query()->whereIn('employees.id', $this->audiences->query((array) ($schedule->audience_criteria ?? []), $actor, $on)),
            'upcoming_joiners' => Employee::query()->whereIn('employees.lifecycle_state', [LifecycleState::PreEmployee->value, LifecycleState::Preboarding->value])
                ->when(filled($schedule->employee_ids), fn (Builder $q) => $q->whereKey(array_map('intval', (array) $schedule->employee_ids))),
            default => Employee::query()->whereKey(array_map('intval', (array) ($schedule->employee_ids ?? []))),
        };
        if (! $schedule->isEvent()) {
            return $query;
        }
        $date = CarbonImmutable::parse($on)->startOfDay()->subDays((int) $schedule->offset_days);
        $leapDay = fn (Builder $q, string $column) => $q->where(fn (Builder $w) => $w->where(fn (Builder $d) => $d->whereMonth($column, $date->month)->whereDay($column, $date->day))
            ->when($date->month === 2 && $date->day === 28 && ! $date->isLeapYear(), fn (Builder $o) => $o->orWhere(fn (Builder $d) => $d->whereMonth($column, 2)->whereDay($column, 29))));

        return match ($schedule->event) {
            'joining_date' => $query->whereDate('employees.joining_date', $date->toDateString()),
            'confirmation_date' => $query->whereDate('employees.confirmation_date', $date->toDateString()),
            'exit_date' => $query->whereDate('employees.exit_date', $date->toDateString()),
            'date_of_birth' => $query->whereHas('person', fn (Builder $p) => $leapDay($p, 'date_of_birth')),
            'work_anniversary' => $leapDay($query, 'employees.joining_date')->whereYear('employees.joining_date', '<', $date->year),
            'resignation_date' => $query->whereHas('exitCases', fn (Builder $e) => $e->where('type', 'resignation')->whereDate('resignation_date', $date->toDateString())),
            'absconding' => $query->whereHas('exitCases', fn (Builder $e) => $e->where('type', 'absconding')->whereDate('initiated_on', $date->toDateString())),
            'retirement_date' => $query->whereHas('person', fn (Builder $p) => $p->whereNotNull('date_of_birth')),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** The event date an employee's letter is for (the occurrence), or null when the event is not today ± the offset. */
    private function eventDate(LetterSchedule $schedule, Employee $employee, CarbonImmutable $today): ?string
    {
        $date = $today->subDays((int) $schedule->offset_days);
        if ($schedule->event !== 'retirement_date') {
            return $date->toDateString();
        }
        $retires = app(RetirementDates::class)->for($employee, Carbon::parse($date))['date'] ?? null;

        return $retires !== null && $retires->isSameDay($date) ? $date->toDateString() : null;
    }

    /**
     * One employee: generate, issue when asked and possible, e-mail when asked and switched on.
     *
     * @param  array<int, LetterSignatory>  $others
     * @return array{0: string, 1: ?Letter, 2: ?string}
     */
    private function one(LetterSchedule $schedule, LetterTemplate $template, Employee $employee, User $actor, ?LetterSignatory $signatory, array $others): array
    {
        try {
            $letter = DB::transaction(fn () => $this->letters->generate($template, $employee, (array) ($schedule->extra ?? []), $actor, null, $signatory, $others ?: null, 'schedule', schedule: $schedule));
        } catch (RuntimeException|LogicException $e) {
            return ['refused', null, $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return ['failed', null, 'The letter could not be generated.'];
        }
        if (! $schedule->issue || $letter->status !== 'approved') {
            return ['generated', $letter, $letter->status === 'pending_approval' ? 'Waiting for approval' : null];
        }
        try {
            $letter = $this->letters->issue($letter, $actor);
        } catch (Throwable $e) {
            report($e);

            return ['generated', $letter, 'Generated; not issued: '.mb_substr($e->getMessage(), 0, 180)];
        }
        $message = null;
        if (filled($schedule->email_to) && app(LetterEmails::class)->enabled()) {
            try {
                app(LetterEmails::class)->send($letter, $actor, (array) $schedule->email_to);
            } catch (RuntimeException $e) {
                $message = 'Issued; not e-mailed: '.mb_substr($e->getMessage(), 0, 180);
            }
        }

        return ['issued', $letter, $message];
    }

    /** @return array{0: ?LetterSignatory, 1: array<int, LetterSignatory>} the first signatory and the others by position */
    private function signatories(LetterSchedule $schedule): array
    {
        $ids = array_values(array_map('intval', array_filter((array) ($schedule->signatory_ids ?? []), 'is_numeric')));
        if ($ids === []) {
            return [null, []];
        }
        $found = LetterSignatory::query()->where('status', ActiveStatus::Active)->whereKey($ids)->get()->keyBy('id');
        $ordered = array_values(array_filter(array_map(fn (int $id) => $found->get($id), $ids)));
        $others = [];
        foreach (array_slice($ordered, 1, 3) as $i => $s) {
            $others[$i + 2] = $s;
        }

        return [$ordered[0] ?? null, $others];
    }

    private function halt(LetterSchedule $schedule, string $reason): void
    {
        $schedule->withAuditReason($reason)->update(['status' => 'paused', 'last_error' => $reason]);
    }

    private function dispatchIfDue(LetterSchedule $schedule): void
    {
        if ($schedule->trigger !== 'one_time') {
            return; // event schedules run with the daily tick
        }
        if ($schedule->run_at === null) {
            RunLetterSchedule::dispatch($schedule->id)->afterCommit();
        } elseif ($schedule->run_at->lte(now()->endOfDay())) {
            RunLetterSchedule::dispatch($schedule->id)->delay($schedule->run_at)->afterCommit();
        }
    }

    private static function mayManage(User $actor): void
    {
        if (! $actor->hasPermission('letter.issue')) {
            throw new RuntimeException('You cannot schedule letters.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, User $actor): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new RuntimeException('Give the schedule a name (at most 255 characters).');
        }
        $template = LetterTemplate::query()->where('status', 'active')->find((int) ($data['letter_template_id'] ?? 0)) ?? throw new RuntimeException('Choose an active letter template.');

        $signatories = array_values(array_unique(array_map('intval', array_filter((array) ($data['signatory_ids'] ?? []), 'is_numeric'))));
        if (count($signatories) > 4) {
            throw new RuntimeException('A letter has at most four signatories.');
        }
        if ($signatories !== [] && LetterSignatory::query()->where('status', ActiveStatus::Active)->whereKey($signatories)->count() !== count($signatories)) {
            throw new RuntimeException('Choose active signatories.');
        }

        $trigger = (string) ($data['trigger'] ?? '');
        if (! array_key_exists($trigger, LetterSchedule::TRIGGERS)) {
            throw new RuntimeException('Choose one time or event based.');
        }
        $runAt = null;
        $event = null;
        $offset = 0;
        if ($trigger === 'one_time') {
            if (($data['publish'] ?? 'now') === 'later') {
                if (blank($data['run_at'] ?? null)) {
                    throw new RuntimeException('Choose the publish date and time.');
                }
                $runAt = Carbon::parse($data['run_at']);
                if ($runAt->lt(now()->subMinute())) {
                    throw new RuntimeException('Choose a publish time from now on.');
                }
            }
        } else {
            $event = (string) ($data['event'] ?? '');
            if (! array_key_exists($event, (array) config('peopleos.letters.schedule_events'))) {
                throw new RuntimeException('Choose the event the letter follows.');
            }
            $days = (int) ($data['offset_days'] ?? 0);
            if ($days < 0 || $days > 365) {
                throw new RuntimeException('The number of days is between 0 and 365.');
            }
            $offset = ($data['offset_direction'] ?? 'after') === 'before' ? -$days : $days;
        }

        $audience = (string) ($data['audience'] ?? '');
        if (! array_key_exists($audience, LetterSchedule::AUDIENCES)) {
            throw new RuntimeException('Choose who the letter is for.');
        }
        $employeeIds = array_values(array_unique(array_map('intval', array_filter((array) ($data['employee_ids'] ?? []), 'is_numeric'))));
        $max = max(1, (int) config('peopleos.letters.schedule_max_employees', 2000));
        if (count($employeeIds) > $max) {
            throw new RuntimeException("At most {$max} named employees.");
        }
        if ($audience === 'employees' && $employeeIds === []) {
            throw new RuntimeException('Choose the employees.');
        }
        if ($employeeIds !== [] && Employee::query()->whereKey($employeeIds)->count() !== count($employeeIds)) {
            throw new RuntimeException('Some of the employees are not in your access.');
        }
        $criteria = null;
        if ($audience === 'criteria') {
            $criteria = $this->audiences->normalise(array_filter((array) ($data['audience_criteria'] ?? []), fn ($v) => is_array($v) && $v !== []));
        }

        $extra = [];
        foreach ((array) ($data['extra'] ?? []) as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            if (! preg_match('/^[a-zA-Z0-9_.]{1,64}$/', $key) || mb_strlen((string) $value) > 500) {
                throw new RuntimeException("Extra value {$key}: use letters, digits, _ and . in the name, and at most 500 characters.");
            }
            $extra[$key] = (string) $value;
        }

        $emailTo = array_values(array_intersect(array_keys(LetterEmails::RECIPIENTS), (array) ($data['email_to'] ?? [])));
        if (in_array('personal', $emailTo, true) && ! $actor->hasPermission('employee.sensitive.view')) {
            throw new RuntimeException('E-mailing to personal e-mails needs access to sensitive personal data.');
        }
        if ($emailTo !== [] && ! app(LetterEmails::class)->enabled()) {
            $emailTo = [];
        }

        return [
            'name' => $name, 'letter_template_id' => $template->id, 'signatory_ids' => $signatories ?: null, 'trigger' => $trigger, 'run_at' => $runAt, 'event' => $event, 'offset_days' => $offset,
            'audience' => $audience, 'audience_criteria' => $criteria ?: null, 'employee_ids' => $employeeIds ?: null, 'extra' => $extra ?: null,
            'issue' => (bool) ($data['issue'] ?? true), 'email_to' => $emailTo ?: null, 'status' => ($data['save_for_later'] ?? false) ? 'saved' : 'active',
        ];
    }
}
