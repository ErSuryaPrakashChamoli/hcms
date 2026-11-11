<?php

namespace App\Domain\Engagement\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Events\EngagementEvent;
use App\Domain\Engagement\Exceptions\EngagementRuleViolation;
use App\Domain\Engagement\Models\Audience;
use App\Domain\Engagement\Models\CompanyEvent;
use App\Domain\Engagement\Models\CompanyEventAttendance;
use App\Domain\Engagement\Models\CompanyEventInvitation;
use App\Domain\Engagement\Models\CompanyEventSession;
use App\Domain\Engagement\Support\Guard;
use App\Domain\Feedback\Models\FeedbackRequest;
use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Feedback\Services\FeedbackRequests;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Domain\Organisation\Models\Location;
use App\Support\Csv\SpreadsheetCell;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * HROne parity GO-15 (Engage → Events, "Add event"): company events from the draft to the attendance sheet.
 *
 * - **Authoring** (engagement.events): what, who hosts, mandatory or optional, seats, when (a start date and time in the
 *   event's time zone, a duration, and an optional daily / weekly rule up to an end date — the sessions are generated when
 *   the event is saved, at most `engagement.event_max_sessions`), where (online / offline / hybrid: a location or venue,
 *   a meeting URL), material (attachments in the tenant's events folder, a video link), the audience (a saved audience or
 *   criteria, plus pasted employee codes) and the optional pre- / post-event feedback templates.
 * - **Publishing** resolves the audience in SQL inside the preparer's access scope (AudienceQuery) and writes one
 *   invitation per person (bulk, one audited operation). Invitees are told in-app (EngagementEvent → the notification
 *   bridge); the social wall posts the event to them when the wall is on. From then on the schedule and audience are fixed.
 * - **Answers** (the invitee only): accept ("Acknowledge" on a mandatory event, which cannot be declined) or decline, until
 *   the event ends. Seats limit acceptances of an optional event: the event row is locked while the seats are counted, so
 *   two people never take the last seat. An invitee may nominate a colleague when the event allows it (the nominee
 *   answers in turn); HR adds invitees at any time while it is published.
 * - **Attendance** per session, by whoever runs events (in their scope) or the event's host, once the session has
 *   started; one row per session and person, re-marking changes it (audited). Downloadable as CSV.
 * - **Cancelling** (with a reason) tells everyone invited who has not declined; nothing is deleted. Completing closes an
 *   event whose last session has started.
 * - **Feedback**: accepting raises the pre-event feedback request and being marked attended the post-event one (process
 *   `event_pre` / `event_post` of the Feedback templates), once per invitation, when the event names a template.
 *
 * Badges for attendance (owner decision D-GO4, off) and skill endorsements (MS-6) are not built.
 */
final class CompanyEvents
{
    public const TITLE_MAX = 200;

    public const REASON_MAX = 500;

    public const ATTACHMENTS_MAX = 5;

    public const DURATION_MAX = 7 * 24 * 60;

    public const REPEATS = ['none' => 'Does not repeat', 'daily' => 'Every day (or every n days)', 'weekly' => 'Every week on chosen days'];

    public function __construct(
        private readonly AudienceQuery $audiences,
        private readonly AuditRecorder $audit,
        private readonly FeedbackRequests $feedback,
        private readonly OptionLists $options,
    ) {}

    /** The tenant's folder for event attachments (DataInventory sweeps it with the tenant). */
    public static function directory(): string
    {
        return 'tenants/'.app(TenantContext::class)->id().'/events';
    }

    /** Whether the person runs company events. */
    public static function runs(?User $user): bool
    {
        return $user !== null && $user->hasPermission('engagement.events');
    }

    /** Whoever runs events, or the event's host, works its invitations and attendance. */
    public function mayRun(User $user, CompanyEvent $event): bool
    {
        if (self::runs($user)) {
            return true;
        }
        $host = $event->host_employee_id === null ? null : AccessScope::withoutScoping(fn () => Employee::query()->whereKey($event->host_employee_id)->value('user_id'));

        return $host !== null && (int) $host === (int) $user->getKey();
    }

    /** Whoever runs events, the host, and the people invited may open an event. */
    public function canView(User $user, CompanyEvent $event): bool
    {
        if ($this->mayRun($user, $event)) {
            return true;
        }
        $me = app(CurrentEmployee::class)->of($user);

        return $me !== null && $event->status !== 'draft' && $this->invitationOf($event, $me) !== null;
    }

    // ------------------------------------------------------------------ authoring

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $actor): CompanyEvent
    {
        Guard::authorise($actor, 'engagement.events');
        $details = $this->details($data);
        $plan = self::plan($data);
        $audience = $this->audienceFrom($data, $actor);

        return DB::transaction(function () use ($details, $plan, $audience, $actor) {
            $event = CompanyEvent::query()->create([...$details, ...$audience, 'timezone' => $plan['timezone'], 'recurrence' => $plan['rule'],
                'starts_at' => $plan['starts'][0], 'ends_at' => end($plan['starts'])->addMinutes($plan['duration']), 'status' => 'draft', 'created_by' => $actor->getKey()]);
            $this->writeSessions($event, $plan);

            return $event;
        });
    }

    /**
     * A draft changes in full (its sessions are generated again); a published event keeps its schedule, audience and
     * mandatory flag, and its seats never drop below the acceptances already made.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(CompanyEvent $event, array $data, User $actor): CompanyEvent
    {
        Guard::authorise($actor, 'engagement.events');

        return DB::transaction(function () use ($event, $data, $actor) {
            $current = CompanyEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($current->status, ['draft', 'published'], true)) {
                throw new EngagementRuleViolation('A cancelled or completed event is not changed.');
            }
            $details = $this->details($data, $current);
            if ($current->status === 'draft') {
                $plan = self::plan($data);
                $current->update([...$details, ...$this->audienceFrom($data, $actor), 'timezone' => $plan['timezone'], 'recurrence' => $plan['rule'],
                    'starts_at' => $plan['starts'][0], 'ends_at' => end($plan['starts'])->addMinutes($plan['duration'])]);
                $current->sessions()->get()->each->delete();
                $this->writeSessions($current, $plan);
            } else {
                unset($details['is_mandatory']);
                $accepted = $this->countStatus($current, 'accepted');
                if (! $current->is_mandatory && $details['seats'] !== null && $details['seats'] < $accepted) {
                    throw new EngagementRuleViolation("{$accepted} people have accepted already: the event needs at least that many seats.");
                }
                if ($current->is_mandatory) {
                    // A mandatory event takes no seats and no nominations (as when it was created).
                    $details['seats'] = null;
                    $details['allow_nomination'] = false;
                }
                $current->update($details);
            }
            $event->setRawAttributes($current->getAttributes(), true);

            return $current;
        });
    }

    /**
     * The sessions a schedule makes, in UTC: the start date and time in the event's time zone, then the repeat rule (every
     * n days, or every n weeks on the chosen weekdays) up to the end date. Local times stay the same across a change of
     * daylight saving time.
     *
     * @param  array<string, mixed>  $data
     * @return array{timezone: string, duration: int, rule: ?array<string, mixed>, starts: list<CarbonImmutable>}
     */
    public static function plan(array $data): array
    {
        $zone = (string) ($data['timezone'] ?? '') ?: (app(TenantContext::class)->current()?->timezone ?: (string) config('app.timezone', 'UTC'));
        if (! in_array($zone, DateTimeZone::listIdentifiers(), true) && $zone !== 'UTC') {
            throw new EngagementRuleViolation('Choose a valid time zone.');
        }
        $date = (string) ($data['start_date'] ?? '');
        $time = substr((string) ($data['start_time'] ?? ''), 0, 5);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}/', $date) || ! preg_match('/^\d{2}:\d{2}$/', $time)) {
            throw new EngagementRuleViolation('An event needs a start date and a start time.');
        }
        $date = substr($date, 0, 10);
        $duration = self::minutes($data['duration'] ?? $data['duration_minutes'] ?? null);
        if ($duration < 1 || $duration > self::DURATION_MAX) {
            throw new EngagementRuleViolation('The duration is between 00:01 and 168:00 (HH:MM).');
        }
        $at = fn (string $day) => CarbonImmutable::parse($day.' '.$time, $zone)->utc();
        $first = CarbonImmutable::parse($date, $zone);
        $repeat = (string) ($data['repeat'] ?? 'none') ?: 'none';
        if (! array_key_exists($repeat, self::REPEATS)) {
            throw new EngagementRuleViolation('Unknown repeat rule.');
        }
        if ($repeat === 'none') {
            return ['timezone' => $zone, 'duration' => $duration, 'rule' => null, 'starts' => [$at($date)]];
        }

        $every = max(1, min(52, (int) ($data['repeat_every'] ?? 1)));
        $until = (string) ($data['repeat_until'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}/', $until) || substr($until, 0, 10) < $date) {
            throw new EngagementRuleViolation('A repeating event needs an end date on or after its start date.');
        }
        $until = CarbonImmutable::parse(substr($until, 0, 10), $zone);
        $max = max(1, (int) config('peopleos.engagement.event_max_sessions', 60));
        $days = [];
        if ($repeat === 'daily') {
            for ($day = $first; $day->lte($until) && count($days) <= $max; $day = $day->addDays($every)) {
                $days[] = $day->toDateString();
            }
        } else {
            $weekdays = array_values(array_unique(array_filter(array_map('intval', (array) ($data['repeat_weekdays'] ?? [])), fn (int $d) => $d >= 1 && $d <= 7)));
            $weekdays = $weekdays === [] ? [$first->dayOfWeekIso] : $weekdays;
            sort($weekdays);
            for ($week = $first->startOfWeek(CarbonInterface::MONDAY); $week->lte($until) && count($days) <= $max; $week = $week->addWeeks($every)) {
                foreach ($weekdays as $weekday) {
                    $day = $week->addDays($weekday - 1);
                    if ($day->gte($first) && $day->lte($until)) {
                        $days[] = $day->toDateString();
                    }
                }
            }
        }
        if (count($days) > $max) {
            throw new EngagementRuleViolation("A repeating event has at most {$max} sessions; choose an earlier end date or repeat less often.");
        }
        if ($days === []) {
            throw new EngagementRuleViolation('The repeat rule gives no session before the end date.');
        }

        return ['timezone' => $zone, 'duration' => $duration, 'rule' => array_filter(['repeat' => $repeat, 'every' => $every, 'weekdays' => $weekdays ?? null, 'until' => $until->toDateString()], fn ($v) => $v !== null),
            'starts' => array_map($at, $days)];
    }

    /** Minutes from "HH:MM", a number of minutes, or null. */
    public static function minutes(mixed $value): int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^(\d{1,3}):([0-5]\d)$/', trim($value), $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }

        return 0;
    }

    /** "01:30" for 90 minutes. */
    public static function hhmm(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    // ------------------------------------------------------------------ publishing

    /** Draft → published: the audience becomes invitations (in the preparer's scope) and the invitees are told. */
    public function publish(CompanyEvent $event, User $actor): CompanyEvent
    {
        Guard::authorise($actor, 'engagement.events');
        $published = null;
        $this->audit->operation('engagement', 'Event invitations', function () use ($event, $actor, &$published) {
            return DB::transaction(function () use ($event, $actor, &$published) {
                $current = CompanyEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
                if ($current->status !== 'draft') {
                    throw new EngagementRuleViolation('Only a draft event is published.');
                }
                $last = $current->sessions()->reorder()->orderByDesc('starts_at')->first();
                if ($last === null) {
                    throw new EngagementRuleViolation('The event has no session.');
                }
                if ($last->endsAt()->lte(now())) {
                    throw new EngagementRuleViolation('Every session of this event is over: it can no longer be published.');
                }
                $criteria = $current->audience_id !== null
                    ? (Audience::query()->whereKey($current->audience_id)->where('status', 'active')->first()?->criteria ?? throw new EngagementRuleViolation('The chosen audience is inactive.'))
                    : ($current->audience_criteria ?? []);
                $criteria = $this->audiences->normalise(is_array($criteria) ? $criteria : (array) json_decode((string) $criteria, true));
                if (! $this->audiences->withinScope($criteria, $actor)) {
                    throw new EngagementRuleViolation('The audience names people or organisation units outside your scope.');
                }
                $count = 0;
                AccessScope::withoutScoping(function () use ($current, $criteria, $actor, &$count) {
                    $this->audiences->query($criteria, $actor)->orderBy('employees.id')->chunk(500, function ($employees) use ($current, &$count) {
                        $now = now();
                        $count += CompanyEventInvitation::query()->insertOrIgnore($employees->map(fn ($e) => [
                            'tenant_id' => $current->tenant_id, 'event_id' => $current->getKey(), 'employee_id' => $e->id, 'status' => 'invited', 'source' => 'audience',
                            'created_at' => $now, 'updated_at' => $now,
                        ])->all());
                    });
                });
                if ($count === 0) {
                    throw new EngagementRuleViolation('The audience reaches nobody employed in your scope.');
                }
                $current->update(['status' => 'published', 'published_at' => now(), 'published_by' => $actor->getKey(), 'scope_user_id' => $actor->getKey(),
                    'audience_criteria' => $criteria, 'invited_count' => $count]);
                $this->audit->record(AuditAction::AudienceUsed, 'engagement', $current, [], null, actor: $actor, metadata: ['purpose' => 'event', 'recipients' => $count, 'criteria' => array_keys($criteria)]);
                $published = $current;

                return ['succeeded' => $count];
            });
        }, entityType: CompanyEventInvitation::class);
        $event->setRawAttributes($published->getAttributes(), true);
        EngagementEvent::dispatch('event.published', $published, $this->refs($published), $this->userIds($published, ['invited']));

        return $published;
    }

    /**
     * HR adds invitees to a published event ("Add bulk employees"): employed people in their scope who are not invited yet.
     *
     * @param  iterable<Employee>  $employees
     * @return int invitations added
     */
    public function invite(CompanyEvent $event, iterable $employees, User $actor): int
    {
        Guard::authorise($actor, 'engagement.events');
        $ids = collect($employees)->map(fn (Employee $e) => (int) $e->getKey())->unique()->values()->all();
        if ($ids === []) {
            return 0;
        }
        if (! $this->audiences->withinScope(['employee_ids' => $ids], $actor)) {
            throw new EngagementRuleViolation('Some of these people are outside your scope.');
        }
        $added = [];
        DB::transaction(function () use ($event, $ids, &$added) {
            $current = $this->openForAnswers($event);
            $employed = AccessScope::withoutScoping(fn () => Employee::query()->employed()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all());
            $known = CompanyEventInvitation::query()->withoutGlobalScope(AccessScope::class)->where('event_id', $current->getKey())->whereIn('employee_id', $employed)->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
            foreach (array_diff($employed, $known) as $id) {
                CompanyEventInvitation::query()->create(['event_id' => $current->getKey(), 'employee_id' => $id, 'status' => 'invited', 'source' => 'hr']);
                $added[] = $id;
            }
            if ($added !== []) {
                $current->update(['invited_count' => (int) $current->invited_count + count($added)]);
            }
        });
        if ($added !== []) {
            $fresh = CompanyEvent::query()->findOrFail($event->getKey());
            EngagementEvent::dispatch('event.published', $fresh, $this->refs($fresh), $this->userIdsOf($added));
        }

        return count($added);
    }

    // ------------------------------------------------------------------ answers

    /** The invitee accepts (acknowledges) or declines. Seats are counted under the event's lock. */
    public function respond(CompanyEventInvitation $invitation, string $status, User $actor): CompanyEventInvitation
    {
        if (! in_array($status, ['accepted', 'declined'], true)) {
            throw new EngagementRuleViolation('Accept or decline the invitation.');
        }
        $invitee = AccessScope::withoutScoping(fn () => Employee::query()->whereKey($invitation->employee_id)->first());
        if ($invitee === null || $invitee->user_id === null || (int) $invitee->user_id !== (int) $actor->getKey()) {
            throw new EngagementRuleViolation('Only the person invited answers an invitation.');
        }
        $changed = false;
        $answered = DB::transaction(function () use ($invitation, $status, &$changed) {
            $event = $this->openForAnswers(CompanyEvent::query()->findOrFail($invitation->event_id));
            $current = CompanyEventInvitation::query()->withoutGlobalScope(AccessScope::class)->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();
            if ($current->status === $status) {
                return $current;
            }
            if ($status === 'declined' && $event->is_mandatory) {
                throw new EngagementRuleViolation('Attending this event is mandatory: acknowledge it instead.');
            }
            if ($status === 'accepted' && $event->limitsSeats() && $this->countStatus($event, 'accepted', $current->getKey()) >= $event->seats) {
                throw new EngagementRuleViolation("The event is full: all {$event->seats} seats are taken.");
            }
            $current->update(['status' => $status, 'responded_at' => now()]);
            $changed = true;

            return $current;
        });
        $invitation->setRawAttributes($answered->getAttributes(), true);
        if ($changed && $status === 'accepted') {
            $this->askFeedback($answered, 'event_pre');
        }

        return $answered;
    }

    /** An invitee nominates a colleague, when the event takes nominations; the colleague answers in turn. */
    public function nominate(CompanyEvent $event, Employee $colleague, User $actor): CompanyEventInvitation
    {
        $me = app(CurrentEmployee::class)->of($actor);
        if ($me === null) {
            throw new EngagementRuleViolation('Only invitees nominate colleagues.');
        }
        $nomination = DB::transaction(function () use ($event, $colleague, $me) {
            $current = $this->openForAnswers($event);
            if (! $current->allow_nomination) {
                throw new EngagementRuleViolation('This event does not take nominations.');
            }
            if ($this->invitationOf($current, $me) === null) {
                throw new EngagementRuleViolation('Only invitees nominate colleagues.');
            }
            if ((int) $colleague->getKey() === (int) $me->getKey()) {
                throw new EngagementRuleViolation('You are invited already.');
            }
            if (! AccessScope::withoutScoping(fn () => Employee::query()->employed()->whereKey($colleague->getKey())->exists())) {
                throw new EngagementRuleViolation('Only people working here can be nominated.');
            }
            if ($this->invitationOf($current, $colleague) !== null) {
                throw new EngagementRuleViolation('That colleague is invited already.');
            }
            if ($current->limitsSeats() && $this->countStatus($current, 'accepted') >= $current->seats) {
                throw new EngagementRuleViolation("The event is full: all {$current->seats} seats are taken.");
            }
            try {
                $invitation = CompanyEventInvitation::query()->create(['event_id' => $current->getKey(), 'employee_id' => $colleague->getKey(), 'status' => 'nominated', 'source' => 'nomination', 'nominated_by' => $me->getKey()]);
            } catch (UniqueConstraintViolationException) {
                throw new EngagementRuleViolation('That colleague is invited already.');
            }
            $current->update(['invited_count' => (int) $current->invited_count + 1]);

            return $invitation;
        });
        $fresh = CompanyEvent::query()->findOrFail($event->getKey());
        EngagementEvent::dispatch('event.nominated', $fresh, $this->refs($fresh) + ['by' => $me->loadMissing('person')->person?->display_name], $this->userIdsOf([(int) $colleague->getKey()]));

        return $nomination;
    }

    // ------------------------------------------------------------------ attendance

    /** Mark one invitee attended or not on one session (re-marking changes it). */
    public function markAttendance(CompanyEventSession $session, Employee $employee, bool $attended, User $by): CompanyEventAttendance
    {
        $event = CompanyEvent::query()->findOrFail($session->event_id);
        if (! $this->mayRun($by, $event)) {
            throw new EngagementRuleViolation('You cannot mark attendance for this event.');
        }
        if ($event->status === 'cancelled') {
            throw new EngagementRuleViolation('This event was cancelled.');
        }
        if (! in_array($event->status, ['published', 'completed'], true)) {
            throw new EngagementRuleViolation('Attendance is marked once the event is published.');
        }
        $session = CompanyEventSession::query()->whereKey($session->getKey())->where('event_id', $event->getKey())->firstOrFail();
        if (! $session->hasStarted()) {
            throw new EngagementRuleViolation('This session has not started yet.');
        }
        $invitation = $this->invitationOf($event, $employee);
        if ($invitation === null) {
            throw new EngagementRuleViolation(($employee->loadMissing('person')->person?->display_name ?? 'This person').' is not invited to this event.');
        }
        if (self::runs($by) && ! $this->audiences->withinScope(['employee_ids' => [(int) $employee->getKey()]], $by)) {
            throw new EngagementRuleViolation('That person is outside your scope.');
        }
        $row = DB::transaction(function () use ($session, $employee, $attended, $by) {
            $existing = CompanyEventAttendance::query()->withoutGlobalScope(AccessScope::class)->where('session_id', $session->getKey())->where('employee_id', $employee->getKey())->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->attended !== $attended) {
                    $existing->update(['attended' => $attended, 'marked_by' => $by->getKey(), 'marked_at' => now()]);
                }

                return $existing;
            }
            try {
                return CompanyEventAttendance::query()->create(['session_id' => $session->getKey(), 'employee_id' => $employee->getKey(), 'attended' => $attended, 'marked_by' => $by->getKey(), 'marked_at' => now()]);
            } catch (UniqueConstraintViolationException) {
                $again = CompanyEventAttendance::query()->withoutGlobalScope(AccessScope::class)->where('session_id', $session->getKey())->where('employee_id', $employee->getKey())->firstOrFail();
                $again->update(['attended' => $attended, 'marked_by' => $by->getKey(), 'marked_at' => now()]);

                return $again;
            }
        });
        if ($attended) {
            $this->askFeedback($invitation, 'event_post');
        }

        return $row;
    }

    /**
     * The attendance sheet: one row per invitee (in the reader's access scope) with the answer and, per session, attended /
     * absent / not marked. One session's column when $session is given ("Select date").
     */
    public function attendanceCsv(CompanyEvent $event, User $actor, ?CompanyEventSession $session = null): string
    {
        if (! $this->mayRun($actor, $event)) {
            throw new EngagementRuleViolation('You cannot download this event\'s attendance.');
        }
        $sessions = $session !== null ? collect([CompanyEventSession::query()->where('event_id', $event->getKey())->findOrFail($session->getKey())]) : $event->sessions()->get();
        $marks = CompanyEventAttendance::query()->withoutGlobalScope(AccessScope::class)->whereIn('session_id', $sessions->pluck('id'))->get()
            ->mapWithKeys(fn (CompanyEventAttendance $a) => [$a->session_id.':'.$a->employee_id => $a->attended]);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Employee code', 'Employee', 'Department', 'Invitation', 'How invited', 'Nominated by', 'Answered on',
            ...$sessions->map(fn (CompanyEventSession $s) => 'Session '.$s->sequence.' · '.$s->starts_at->copy()->setTimezone($event->timezone)->format('Y-m-d H:i'))->all()]);
        $query = CompanyEventInvitation::query()->where('event_id', $event->getKey())
            ->with(['employee.person', 'employee.currentPosition.department', 'nominator.person'])->orderBy('id');
        if (! self::runs($actor)) {
            // The host sees every invitee of their own event (they run its attendance), not only their access scope.
            $query->withoutGlobalScope(AccessScope::class);
        }
        $query->chunk(500, function ($invitations) use ($out, $sessions, $marks) {
            foreach ($invitations as $invitation) {
                $row = [$invitation->employee?->employee_code, $invitation->employee?->person?->full_name, $invitation->employee?->currentPosition?->department?->name,
                    $invitation->statusLabel(), CompanyEventInvitation::SOURCES[$invitation->source] ?? $invitation->source, $invitation->nominator?->person?->full_name,
                    $invitation->responded_at?->format('Y-m-d H:i')];
                foreach ($sessions as $s) {
                    $mark = $marks[$s->id.':'.$invitation->employee_id] ?? null;
                    $row[] = $mark === null ? '' : ($mark ? 'Attended' : 'Absent');
                }
                fputcsv($out, array_map(fn ($v) => self::cell($v), $row));
            }
        });
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        $this->audit->record(AuditAction::Export, 'engagement', $event, [], 'attendance download', actor: $actor, metadata: ['export' => 'event_attendance', 'sessions' => $sessions->count()]);

        return $csv;
    }

    // ------------------------------------------------------------------ closing

    /** Draft or published → cancelled, with a reason; everyone invited who has not declined is told. Nothing is deleted. */
    public function cancel(CompanyEvent $event, string $reason, User $actor): CompanyEvent
    {
        Guard::authorise($actor, 'engagement.events');
        $reason = trim(strip_tags($reason));
        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new EngagementRuleViolation('Cancelling an event needs a reason of at most '.self::REASON_MAX.' characters.');
        }
        $cancelled = DB::transaction(function () use ($event, $reason, $actor) {
            $current = CompanyEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($current->status, ['draft', 'published'], true)) {
                throw new EngagementRuleViolation('Only a draft or published event is cancelled.');
            }
            $current->withAuditReason($reason)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->getKey(), 'cancel_reason' => $reason]);

            return $current;
        });
        $event->setRawAttributes($cancelled->getAttributes(), true);
        $this->cancelFeedback($cancelled, 'Event cancelled: '.$reason);
        $recipients = $this->userIds($cancelled, ['invited', 'nominated', 'accepted']);
        if ($recipients !== []) {
            EngagementEvent::dispatch('event.cancelled', $cancelled, $this->refs($cancelled) + ['reason' => Str::limit($reason, 200)], $recipients);
        }

        return $cancelled;
    }

    /** Published → completed, once its last session has started. */
    public function complete(CompanyEvent $event, User $actor): CompanyEvent
    {
        Guard::authorise($actor, 'engagement.events');
        $completed = DB::transaction(function () use ($event) {
            $current = CompanyEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            if ($current->status !== 'published') {
                throw new EngagementRuleViolation('Only a published event is completed.');
            }
            $last = $current->sessions()->reorder()->orderByDesc('starts_at')->first();
            if ($last !== null && ! $last->hasStarted()) {
                throw new EngagementRuleViolation('The event is not over yet: its last session starts '.$last->label($current->timezone).'.');
            }
            $current->update(['status' => 'completed', 'completed_at' => now()]);

            return $current;
        });
        $event->setRawAttributes($completed->getAttributes(), true);

        return $completed;
    }

    // ------------------------------------------------------------------ reading

    /** @return array{invited: int, pending: int, nominated: int, accepted: int, declined: int, seats_left: ?int} */
    public function stats(CompanyEvent $event): array
    {
        $counts = CompanyEventInvitation::query()->withoutGlobalScope(AccessScope::class)->where('event_id', $event->getKey())
            ->select('status', DB::raw('count(*) as n'))->groupBy('status')->toBase()->get()->pluck('n', 'status')->map(fn ($n) => (int) $n);
        $accepted = (int) ($counts['accepted'] ?? 0);

        return ['invited' => (int) $counts->sum(), 'pending' => (int) (($counts['invited'] ?? 0) + ($counts['nominated'] ?? 0)), 'nominated' => (int) ($counts['nominated'] ?? 0),
            'accepted' => $accepted, 'declined' => (int) ($counts['declined'] ?? 0), 'seats_left' => $event->limitsSeats() ? max(0, (int) $event->seats - $accepted) : null];
    }

    /** People marked attended on a session. */
    public function attendedCount(CompanyEventSession $session): int
    {
        return CompanyEventAttendance::query()->withoutGlobalScope(AccessScope::class)->where('session_id', $session->getKey())->where('attended', true)->count();
    }

    /**
     * The employee's invitations to published events that have not ended (declined ones left out), soonest first.
     *
     * @return Collection<int, CompanyEventInvitation>
     */
    public function upcomingFor(Employee $employee, int $limit = 3): Collection
    {
        return $this->liveInvitations($employee)->where('company_event_invitations.status', '!=', 'declined')->limit($limit)->get();
    }

    /**
     * Invitations still waiting for the employee's answer (My tasks), soonest first.
     *
     * @return Collection<int, CompanyEventInvitation>
     */
    public function pendingFor(Employee $employee): Collection
    {
        return $this->liveInvitations($employee)->whereIn('company_event_invitations.status', CompanyEventInvitation::PENDING)->limit(50)->get();
    }

    /** All the employee's invitations (My events), newest event first. */
    public function invitationsQuery(Employee $employee): Builder
    {
        return CompanyEventInvitation::query()->withoutGlobalScope(AccessScope::class)->where('company_event_invitations.employee_id', $employee->getKey())
            ->whereHas('event', fn (Builder $q) => $q->whereIn('status', ['published', 'completed', 'cancelled']))
            ->with(['event.sessions', 'event.host.person', 'event.location', 'nominator.person']);
    }

    /** The session that comes next (or is under way); the last one once all have ended. */
    public function nextSession(CompanyEvent $event): ?CompanyEventSession
    {
        $sessions = $event->relationLoaded('sessions') ? $event->sessions : $event->sessions()->get();

        return $sessions->first(fn (CompanyEventSession $s) => $s->endsAt()->gt(now())) ?? $sessions->last();
    }

    public function invitationOf(CompanyEvent $event, Employee $employee): ?CompanyEventInvitation
    {
        return CompanyEventInvitation::query()->withoutGlobalScope(AccessScope::class)->where('event_id', $event->getKey())->where('employee_id', $employee->getKey())->first();
    }

    /** @return array<string, mixed> references for notifications and the wall (never the audience or the attachments) */
    public function refs(CompanyEvent $event): array
    {
        $first = $event->sessions()->first();

        return ['event' => $event->title, 'type' => $event->typeLabel(), 'when' => $first?->label($event->timezone), 'place' => $event->placeLabel(),
            'mandatory' => (bool) $event->is_mandatory];
    }

    /** A signed link to one attachment (ParityFileController re-checks who may open the event). */
    public function attachmentUrl(CompanyEvent $event, int $index): ?string
    {
        $path = array_values((array) ($event->attachments ?? []))[$index] ?? null;

        return $path === null ? null : URL::temporarySignedRoute('parity.file', now()->addMinutes(30), ['type' => 'company-event', 'id' => $event->getKey(), 'index' => $index]);
    }

    // ------------------------------------------------------------------ internals

    /** @return Builder<CompanyEventInvitation> */
    private function liveInvitations(Employee $employee): Builder
    {
        return CompanyEventInvitation::query()->withoutGlobalScope(AccessScope::class)->select('company_event_invitations.*')
            ->join('company_events', 'company_events.id', '=', 'company_event_invitations.event_id')
            ->where('company_event_invitations.employee_id', $employee->getKey())
            ->where('company_events.status', 'published')->where('company_events.ends_at', '>', now())
            ->orderBy('company_events.starts_at')->orderBy('company_events.id')
            ->with(['event.sessions', 'event.host.person', 'event.location']);
    }

    /** The event, locked, while invitations can be answered (published, not over). */
    private function openForAnswers(CompanyEvent $event): CompanyEvent
    {
        $current = CompanyEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
        if ($current->status === 'cancelled') {
            throw new EngagementRuleViolation('This event was cancelled.');
        }
        if ($current->status !== 'published') {
            throw new EngagementRuleViolation('This event is not open for answers.');
        }
        if ($current->ends_at !== null && $current->ends_at->lte(now())) {
            throw new EngagementRuleViolation('This event is over.');
        }

        return $current;
    }

    private function countStatus(CompanyEvent $event, string $status, ?int $except = null): int
    {
        return CompanyEventInvitation::query()->withoutGlobalScope(AccessScope::class)->where('event_id', $event->getKey())->where('status', $status)
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except))->sharedLock()->count();
    }

    /**
     * Event details as stored, checked.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function details(array $data, ?CompanyEvent $current = null): array
    {
        $title = trim(strip_tags((string) ($data['title'] ?? '')));
        if ($title === '' || mb_strlen($title) > self::TITLE_MAX) {
            throw new EngagementRuleViolation('An event needs a name of at most '.self::TITLE_MAX.' characters.');
        }
        foreach (['event_type' => 'event_types', 'event_sub_type' => 'event_sub_types'] as $field => $list) {
            $value = $data[$field] ?? null;
            if (filled($value) && ! array_key_exists((string) $value, $this->options->options($list)) && (string) $value !== (string) $current?->getAttribute($field)) {
                throw new EngagementRuleViolation('Choose the '.str_replace('_', ' ', $field).' from the list.');
            }
        }
        $mandatory = (bool) ($data['is_mandatory'] ?? false);
        $seats = filled($data['seats'] ?? null) ? (int) $data['seats'] : null;
        if ($seats !== null && $seats < 1) {
            throw new EngagementRuleViolation('Seats are at least 1 (leave it empty for unlimited).');
        }
        $mode = (string) ($data['mode'] ?? 'offline');
        if (! array_key_exists($mode, CompanyEvent::MODES)) {
            throw new EngagementRuleViolation('Choose how the event is held: online, offline or hybrid.');
        }
        $location = filled($data['location_id'] ?? null) ? (int) $data['location_id'] : null;
        if ($location !== null && ! Location::query()->whereKey($location)->exists()) {
            throw new EngagementRuleViolation('Choose a location from the list.');
        }
        $venue = filled($data['venue'] ?? null) ? Str::limit(trim(strip_tags((string) $data['venue'])), 250, '') : null;
        $url = $this->url($data['meeting_url'] ?? null, 'meeting URL');
        if ($mode !== 'offline' && $url === null) {
            throw new EngagementRuleViolation('An online or hybrid event needs a meeting URL.');
        }
        if ($mode !== 'online' && $location === null && $venue === null) {
            throw new EngagementRuleViolation('An offline or hybrid event needs a location or a venue.');
        }
        $host = filled($data['host_employee_id'] ?? null) ? (int) $data['host_employee_id'] : null;
        if ($host !== null && $host !== (int) $current?->host_employee_id && ! AccessScope::withoutScoping(fn () => Employee::query()->employed()->whereKey($host)->exists())) {
            throw new EngagementRuleViolation('The host is someone working here.');
        }

        return [
            'title' => $title, 'event_type' => filled($data['event_type'] ?? null) ? (string) $data['event_type'] : null,
            'event_sub_type' => filled($data['event_sub_type'] ?? null) ? (string) $data['event_sub_type'] : null,
            'description' => filled($data['description'] ?? null) ? Str::limit(trim((string) $data['description']), 5000, '') : null,
            'agenda' => filled($data['agenda'] ?? null) ? Str::limit(trim((string) $data['agenda']), 10000, '') : null,
            'host_employee_id' => $host, 'is_mandatory' => $mandatory, 'allow_nomination' => ! $mandatory && (bool) ($data['allow_nomination'] ?? false),
            'seats' => $mandatory ? null : $seats, 'mode' => $mode, 'location_id' => $mode === 'online' ? null : $location, 'venue' => $mode === 'online' ? null : $venue,
            'meeting_url' => $mode === 'offline' ? null : $url, 'video_url' => $this->url($data['video_url'] ?? null, 'video link'),
            'attachments' => $this->attachments($data['attachments'] ?? null, $current),
            'pre_feedback_template_id' => $this->template($data['pre_feedback_template_id'] ?? null, 'event_pre'),
            'post_feedback_template_id' => $this->template($data['post_feedback_template_id'] ?? null, 'event_post'),
        ];
    }

    private function url(mixed $value, string $what): ?string
    {
        if (blank($value)) {
            return null;
        }
        $value = trim((string) $value);
        if (mb_strlen($value) > 500 || filter_var($value, FILTER_VALIDATE_URL) === false || ! preg_match('#^https?://#i', $value)) {
            throw new EngagementRuleViolation("The {$what} is a web address (https://…) of at most 500 characters.");
        }

        return $value;
    }

    /** @return list<string>|null stored files in the tenant's events folder (kept files of the event stay valid) */
    private function attachments(mixed $paths, ?CompanyEvent $current): ?array
    {
        $paths = array_values(array_filter(array_map('strval', (array) ($paths ?? []))));
        if ($paths === []) {
            return null;
        }
        if (count($paths) > self::ATTACHMENTS_MAX) {
            throw new EngagementRuleViolation('An event has at most '.self::ATTACHMENTS_MAX.' attachments.');
        }
        $kept = (array) ($current?->attachments ?? []);
        $disk = Storage::disk(config('peopleos.documents.disk', 'local'));
        foreach ($paths as $path) {
            if (! in_array($path, $kept, true) && (! str_starts_with($path, self::directory().'/') || str_contains($path, '..') || ! $disk->exists($path))) {
                throw new EngagementRuleViolation('Attach the file again: it could not be found.');
            }
        }

        return $paths;
    }

    private function template(mixed $id, string $process): ?int
    {
        if (blank($id)) {
            return null;
        }
        if (! FeedbackTemplate::query()->active()->where('process', $process)->whereKey((int) $id)->exists()) {
            throw new EngagementRuleViolation('Choose an active '.($process === 'event_pre' ? 'pre-event' : 'post-event').' feedback template.');
        }

        return (int) $id;
    }

    /**
     * The audience: a saved audience, or criteria (plus pasted employee codes, which become named employees), checked to
     * stay inside the preparer's scope.
     *
     * @param  array<string, mixed>  $data
     * @return array{audience_id: ?int, audience_criteria: ?array<string, mixed>}
     */
    private function audienceFrom(array $data, User $actor): array
    {
        $audienceId = filled($data['audience_id'] ?? null) ? (int) $data['audience_id'] : null;
        if ($audienceId !== null) {
            if (! Audience::query()->whereKey($audienceId)->where('status', 'active')->exists()) {
                throw new EngagementRuleViolation('The chosen audience is inactive.');
            }

            return ['audience_id' => $audienceId, 'audience_criteria' => null];
        }
        $criteria = collect((array) ($data['audience_criteria'] ?? []))->filter(fn ($v) => is_array($v) && $v !== [])->all();
        $codes = array_values(array_unique(array_filter(preg_split('/[\s,;]+/', (string) ($data['employee_codes'] ?? '')) ?: [])));
        if ($codes !== []) {
            $found = Employee::query()->whereIn('employee_code', $codes)->pluck('id', 'employee_code');
            $unknown = array_diff($codes, $found->keys()->map(fn ($c) => (string) $c)->all());
            if ($unknown !== []) {
                throw new EngagementRuleViolation('Unknown employee codes (or outside your scope): '.implode(', ', array_slice($unknown, 0, 10)).(count($unknown) > 10 ? '…' : '').'.');
            }
            $criteria['employee_ids'] = array_values(array_unique([...array_map('intval', (array) ($criteria['employee_ids'] ?? [])), ...$found->values()->map(fn ($id) => (int) $id)->all()]));
        }
        $criteria = $this->audiences->normalise($criteria);
        if (! $this->audiences->withinScope($criteria, $actor)) {
            throw new EngagementRuleViolation('The audience names people or organisation units outside your scope.');
        }

        return ['audience_id' => null, 'audience_criteria' => $criteria === [] ? null : $criteria];
    }

    /** @param  array{duration: int, starts: list<CarbonImmutable>}  $plan */
    private function writeSessions(CompanyEvent $event, array $plan): void
    {
        foreach ($plan['starts'] as $i => $start) {
            CompanyEventSession::query()->create(['event_id' => $event->getKey(), 'sequence' => $i + 1, 'starts_at' => $start, 'duration_minutes' => $plan['duration']]);
        }
    }

    /**
     * @param  list<string>  $statuses
     * @return list<int> the users of the invitees in these statuses
     */
    private function userIds(CompanyEvent $event, array $statuses): array
    {
        $ids = CompanyEventInvitation::query()->withoutGlobalScope(AccessScope::class)->where('event_id', $event->getKey())->whereIn('status', $statuses)->pluck('employee_id')->map(fn ($id) => (int) $id)->all();

        return $this->userIdsOf($ids);
    }

    /**
     * @param  list<int>  $employeeIds
     * @return list<int>
     */
    private function userIdsOf(array $employeeIds): array
    {
        return collect(array_chunk($employeeIds, 1000))->flatMap(fn (array $chunk) => AccessScope::withoutScoping(fn () => Employee::query()->whereIn('id', $chunk)->whereNotNull('user_id')->pluck('user_id')))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** Raise the event's pre- or post-event feedback request for the invitee, once; a failure never stops the answer. */
    private function askFeedback(CompanyEventInvitation $invitation, string $process): void
    {
        try {
            $event = CompanyEvent::query()->find($invitation->event_id);
            $template = $process === 'event_pre' ? $event?->pre_feedback_template_id : $event?->post_feedback_template_id;
            if ($event === null || $template === null) {
                return;
            }
            $employee = AccessScope::withoutScoping(fn () => Employee::query()->find($invitation->employee_id));
            if ($employee === null || $employee->user_id === null) {
                return;
            }
            $due = $process === 'event_pre' ? CarbonImmutable::parse($event->starts_at)->setTimezone($event->timezone)->startOfDay() : now()->addDays(7)->startOfDay();
            $this->feedback->raise($template, $process, $invitation, $employee, 'employee', (int) $employee->user_id, null, $due,
                ($process === 'event_pre' ? 'Pre-event feedback: ' : 'Post-event feedback: ').$event->title);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Pending feedback requests of a cancelled event's invitations are cancelled with it. */
    private function cancelFeedback(CompanyEvent $event, string $reason): void
    {
        try {
            $ids = FeedbackRequest::query()->withoutGlobalScope(AccessScope::class)->where('subject_type', (new CompanyEventInvitation)->getMorphClass())->where('status', 'pending')
                ->whereIn('subject_id', CompanyEventInvitation::query()->withoutGlobalScope(AccessScope::class)->where('event_id', $event->getKey())->select('id'))->pluck('subject_id');
            CompanyEventInvitation::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $ids)->get()->each(fn (CompanyEventInvitation $i) => $this->feedback->cancelFor($i, $reason));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Spreadsheet formula injection: a cell starting with = + - @ is read as text. */
    private static function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return SpreadsheetCell::guard($text);
    }
}
