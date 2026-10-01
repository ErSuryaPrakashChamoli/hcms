<?php

namespace App\Domain\Learning\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Learning\Events\LearningEvent;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Models\TrainingSession;
use App\Domain\Learning\Models\TrainingSessionAttendee;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registration and attendance for classroom / virtual sessions (Phase 8: capacity under a row lock,
 * waitlist, promotion on cancellation). The session row is locked (SELECT … FOR UPDATE on MySQL)
 * before seats are counted, so two registrations for the last seat cannot both succeed.
 */
final class TrainingSessions
{
    public function __construct(private readonly Learning $learning) {}

    public function register(TrainingSession $session, Employee $employee, ?User $actor = null): TrainingSessionAttendee
    {
        $course = $session->course()->firstOrFail();

        return DB::transaction(function () use ($session, $employee, $actor, $course) {
            $locked = TrainingSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'scheduled') {
                throw new RuntimeException('The session is not open for registration.');
            }
            $existing = TrainingSessionAttendee::query()->withoutGlobalScope(AccessScope::class)->where('training_session_id', $locked->id)->where('employee_id', $employee->id)->first();
            if ($existing && in_array($existing->status, ['registered', 'waitlisted', 'attended'], true)) {
                return $existing; // idempotent
            }

            $taken = TrainingSessionAttendee::query()->withoutGlobalScope(AccessScope::class)->where('training_session_id', $locked->id)->whereIn('status', ['registered', 'attended'])->count();
            $full = $locked->capacity !== null && $taken >= $locked->capacity;

            $enrolment = $this->learning->enrol($employee, $course, $locked->starts_at->copy()->startOfDay(), null, null, $actor);
            if ($full && in_array($enrolment->status, ['enrolled', 'assigned', 'approved'], true)) {
                $enrolment->update(['status' => 'waitlisted']);
            }
            $enrolment->forceFill(['training_session_id' => $locked->id])->saveQuietly();

            $position = $full ? (int) TrainingSessionAttendee::query()->withoutGlobalScope(AccessScope::class)->where('training_session_id', $locked->id)->max('waitlist_position') + 1 : null;
            $attributes = ['learning_enrolment_id' => $enrolment->id, 'status' => $full ? 'waitlisted' : 'registered', 'waitlist_position' => $position, 'registered_at' => now(), 'cancelled_at' => null];
            $attendee = $existing ? tap($existing)->update($attributes) : TrainingSessionAttendee::query()->create(['training_session_id' => $locked->id, 'employee_id' => $employee->id, ...$attributes]);

            LearningEvent::dispatch($full ? 'learning.session.waitlisted' : 'learning.session.registered', $employee, $locked, ['session' => $locked->title, 'starts_at' => $locked->starts_at->toDateTimeString(), 'venue' => $locked->venue ?? $locked->meeting_url]);

            return $attendee;
        });
    }

    /** Cancel a registration; the first waitlisted learner takes the freed seat. */
    public function cancel(TrainingSessionAttendee $attendee, string $reason, ?User $actor = null): ?TrainingSessionAttendee
    {
        return DB::transaction(function () use ($attendee, $reason, $actor) {
            $session = TrainingSession::query()->whereKey($attendee->training_session_id)->lockForUpdate()->firstOrFail();
            $current = TrainingSessionAttendee::query()->withoutGlobalScope(AccessScope::class)->whereKey($attendee->id)->lockForUpdate()->firstOrFail();
            if (! in_array($current->status, ['registered', 'waitlisted'], true)) {
                throw new RuntimeException('Only a registered or waitlisted place can be cancelled.');
            }
            $freed = $current->status === 'registered';
            $current->update(['status' => 'cancelled', 'cancelled_at' => now(), 'waitlist_position' => null]);
            $enrolment = LearningEnrolment::query()->withoutGlobalScope(AccessScope::class)->find($current->learning_enrolment_id);
            if ($enrolment && ($enrolment->isOpen() || $enrolment->isPending()) && $enrolment->started_at === null) {
                $this->learning->cancel($enrolment, $reason, $actor);
            }

            if (! $freed) {
                return null;
            }
            $next = TrainingSessionAttendee::query()->withoutGlobalScope(AccessScope::class)->where('training_session_id', $session->id)->where('status', 'waitlisted')->orderBy('waitlist_position')->lockForUpdate()->first();
            if ($next === null) {
                return null;
            }
            $next->update(['status' => 'registered', 'waitlist_position' => null]);
            LearningEnrolment::query()->withoutGlobalScope(AccessScope::class)->whereKey($next->learning_enrolment_id)->where('status', 'waitlisted')->get()->each(fn ($e) => $e->update(['status' => 'enrolled']));
            LearningEvent::dispatch('learning.session.registered', $next->employee()->withoutGlobalScope(AccessScope::class)->first(), $session, ['session' => $session->title, 'starts_at' => $session->starts_at->toDateTimeString(), 'venue' => $session->venue ?? $session->meeting_url]);

            return $next;
        });
    }

    /** @param  array<int, string>  $statuses  employee id => attended | absent */
    public function markAttendance(TrainingSession $session, array $statuses, ?User $actor = null): int
    {
        $marked = 0;

        foreach ($session->attendees()->with('enrolment')->where('status', '!=', 'cancelled')->get() as $attendee) {
            $status = $statuses[$attendee->employee_id] ?? null;
            if (! in_array($status, ['attended', 'absent'], true)) {
                continue;
            }
            $attendee->update(['status' => $status]);
            $marked++;

            if ($status === 'attended' && $attendee->enrolment?->isOpen()) {
                $version = $attendee->enrolment->courseVersion()->first();
                $this->learning->start($attendee->enrolment);
                $attendee->enrolment->update(['progress' => 100]);
                if (($version?->assessment ?? null) === null) {
                    $this->learning->complete($attendee->enrolment, null, $actor, ['attendance' => 'full']);
                }
            }
        }

        return $marked;
    }

    public function close(TrainingSession $session, ?User $actor = null): TrainingSession
    {
        $session->update(['status' => 'completed']);

        return $session;
    }
}
