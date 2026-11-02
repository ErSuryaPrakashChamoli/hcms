<?php

namespace App\Domain\Engagement\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Employment\Imports\RowImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Models\CompanyEvent;
use App\Domain\Engagement\Models\CompanyEventAttendance;
use App\Domain\Engagement\Models\CompanyEventSession;
use App\Domain\Engagement\Services\CompanyEvents;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use RuntimeException;

/**
 * Data import (Engage → Event attendance): who attended a company event, one row per invitee and session, marked through
 * CompanyEvents::markAttendance — the same rules as the event's attendance screen (whoever runs events or the host, a
 * published or completed event, a session that has started, an invited person in scope). The event is named by its
 * number or its exact title; the session by its number or its date, and may be left blank when the event has one.
 * A row whose mark is already on file with the same answer is skipped; re-marking changes it.
 */
final class EventAttendanceImports extends RowImports
{
    public const TYPE = 'event_attendance';

    /** Words read as attended / absent (the attendance download writes Attended / Absent). */
    private const ANSWERS = ['attended' => true, 'present' => true, 'yes' => true, 'y' => true, '1' => true, 'true' => true,
        'absent' => false, 'no' => false, 'n' => false, '0' => false, 'false' => false];

    /** @var array<int, ?User> */
    private array $uploaders = [];

    public static function fields(): array
    {
        return ['event' => ['Event', true], 'session' => ['Session', false], 'employee_code' => ['Employee code', true], 'attended' => ['Attended', true]];
    }

    public function label(): string
    {
        return 'Event attendance upload';
    }

    protected function module(): string
    {
        return 'engagement';
    }

    protected function entityType(): string
    {
        return CompanyEventAttendance::class;
    }

    protected function aliases(): array
    {
        return ['event_title' => 'event', 'event_number' => 'event', 'event_no' => 'event', 'session_number' => 'session', 'session_date' => 'session', 'date' => 'session',
            'attendance' => 'attended', 'status' => 'attended', 'present' => 'attended'];
    }

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        [$employeeId, $errors] = $this->employeeOf($r, $context);
        $attended = self::ANSWERS[mb_strtolower(trim((string) ($r['attended'] ?? '')))] ?? null;
        if ($attended === null) {
            $errors[] = 'Attended must be Attended or Absent (yes / no also work).';
        }
        [$event, $session, $problem] = $this->resolve($r, $context);
        if ($problem !== null) {
            $errors[] = $problem;
        }
        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'employee_id' => $employeeId];
        }
        $key = $session->getKey().'|'.$employeeId;
        if (isset($context['seen'][$key])) {
            return ['action' => 'error', 'errors' => ['The same person and session are in row '.$context['seen'][$key].'.'], 'employee_id' => $employeeId];
        }
        $context['seen'][$key] = $row->row_number;

        $uploader = $this->uploader($import);
        $events = app(CompanyEvents::class);
        $employee = Employee::query()->find($employeeId);
        $refusal = match (true) {
            $uploader === null || ! $events->mayRun($uploader, $event) => 'You cannot mark attendance for this event (it needs engagement.events, or being its host).',
            $event->status === 'cancelled' => 'This event was cancelled.',
            ! in_array($event->status, ['published', 'completed'], true) => 'Attendance is marked once the event is published.',
            ! $session->hasStarted() => 'This session has not started yet.',
            $employee === null => 'That employee is outside your organisation scope.',
            $events->invitationOf($event, $employee) === null => ($employee->loadMissing('person')->person?->display_name ?? 'This person').' is not invited to this event.',
            default => null,
        };
        if ($refusal !== null) {
            return ['action' => 'error', 'errors' => [$refusal], 'employee_id' => $employeeId];
        }

        $mark = CompanyEventAttendance::query()->withoutGlobalScope(AccessScope::class)->where('session_id', $session->getKey())->where('employee_id', $employeeId)->first();
        if ($mark === null) {
            return ['action' => 'create', 'employee_id' => $employeeId];
        }

        return (bool) $mark->attended === $attended
            ? ['action' => 'skip', 'match' => ['reason' => 'Already marked '.($attended ? 'attended' : 'absent').'.'], 'employee_id' => $employeeId]
            : ['action' => 'update', 'match' => ['id' => $mark->getKey()], 'employee_id' => $employeeId];
    }

    protected function apply(array $r, EmployeeImportRow $row, EmployeeImport $import, User $user): string
    {
        $context = [];
        [, $session, $problem] = $this->resolve($r, $context);
        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
        $employee = Employee::query()->find($row->employee_id) ?? throw new RuntimeException('That employee is outside your organisation scope.');
        $attended = self::ANSWERS[mb_strtolower(trim((string) $r['attended']))];
        app(CompanyEvents::class)->markAttendance($session, $employee, $attended, $user);

        return ($attended ? 'Attended' : 'Absent').' · session '.$session->sequence;
    }

    /**
     * The event and session a row names (event by number or exact title; session by number or date, blank for a one-session
     * event), or why not.
     *
     * @return array{0: ?CompanyEvent, 1: ?CompanyEventSession, 2: ?string}
     */
    private function resolve(array $r, array &$context): array
    {
        $given = trim((string) ($r['event'] ?? ''));
        $number = ltrim($given, '#');
        $context['events'][$given] ??= ctype_digit($number)
            ? CompanyEvent::query()->whereKey((int) $number)->get()
            : CompanyEvent::query()->whereRaw('lower(title) = ?', [mb_strtolower($given)])->get();
        $found = $context['events'][$given];
        if ($found->isEmpty()) {
            return [null, null, "Event '{$given}' does not exist."];
        }
        if ($found->count() > 1) {
            return [null, null, "Several events are called '{$given}': give the event number instead (".$found->pluck('id')->implode(', ').').'];
        }
        $event = $found->first();
        $sessions = $context['sessions'][$event->getKey()] ??= CompanyEventSession::query()->where('event_id', $event->getKey())->orderBy('sequence')->get();
        $which = trim((string) ($r['session'] ?? ''));
        if ($which === '') {
            return $sessions->count() === 1 ? [$event, $sessions->first(), null] : [$event, null, "This event has {$sessions->count()} sessions: give the session number or date."];
        }
        if (ctype_digit($which)) {
            $session = $sessions->firstWhere('sequence', (int) $which);

            return $session ? [$event, $session, null] : [$event, null, "This event has no session {$which}."];
        }
        $date = $this->date($which);
        if ($date === null) {
            return [$event, null, 'Session must be the session number or its date (YYYY-MM-DD).'];
        }
        $onDay = $sessions->filter(fn (CompanyEventSession $s) => $s->starts_at->copy()->setTimezone($event->timezone ?: 'UTC')->toDateString() === $date);

        return match ($onDay->count()) {
            1 => [$event, $onDay->first(), null],
            0 => [$event, null, "This event has no session on {$date}."],
            default => [$event, null, "This event has several sessions on {$date}: give the session number."],
        };
    }

    private function uploader(EmployeeImport $import): ?User
    {
        return $this->uploaders[(int) $import->getKey()] ??= User::query()->find($import->uploaded_by);
    }

    public function format(): array
    {
        return [
            'columns' => [
                ['Event', 'Yes', 'The event\'s number, or its exact title (Engage › Events). Use the number when two events share a title.', 'Annual Day 2026'],
                ['Session', 'No', 'The session number (1, 2 …) or its date (YYYY-MM-DD). Leave blank for an event with one session.', '1'],
                ['Employee code', 'Yes', 'An invited employee\'s code.', 'EMP0001'],
                ['Attended', 'Yes', 'Attended or Absent (yes / no also work), as the attendance download writes it.', 'Attended'],
            ],
            'notes' => [
                'Attendance follows the event\'s own rules: a published or completed event, a session that has started, and people invited to it.',
                'Marking someone attended asks them for their feedback on the event when it has a feedback form, as marking on the screen does.',
                'A mark already on file with the same answer is skipped; a different answer changes it.',
            ],
        ];
    }

    protected function examples(): array
    {
        $event = CompanyEvent::query()->whereIn('status', ['published', 'completed'])->orderByDesc('id')->value('title') ?? 'Annual Day 2026';
        $code = Employee::query()->orderBy('id')->value('employee_code') ?? 'EMP0001';
        $other = Employee::query()->orderBy('id')->skip(1)->value('employee_code') ?? 'EMP0002';

        return [[$event, '1', $code, 'Attended'], [$event, '1', $other, 'Absent']];
    }
}
