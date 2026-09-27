<?php

namespace App\Domain\Learning\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Events\LearningEvent;
use App\Domain\Learning\Models\TrainingSession;
use App\Domain\Learning\Models\TrainingSessionAttendee;
use RuntimeException;

/** Registration and attendance for classroom / virtual sessions; attendance completes the enrolment. */
final class TrainingSessions
{
    public function __construct(private readonly Learning $learning) {}

    public function register(TrainingSession $session, Employee $employee, ?User $actor = null): TrainingSessionAttendee
    {
        if ($session->status !== 'scheduled') {
            throw new RuntimeException('The session is not open for registration.');
        }
        if ($session->seatsLeft() === 0) {
            throw new RuntimeException('The session is full.');
        }

        $course = $session->course()->firstOrFail();
        $enrolment = $this->learning->enrol($employee, $course, $session->starts_at->copy()->startOfDay(), null, null, $actor);

        $attendee = TrainingSessionAttendee::query()->firstOrCreate(
            ['training_session_id' => $session->id, 'employee_id' => $employee->id],
            ['learning_enrolment_id' => $enrolment->id, 'status' => 'registered'],
        );
        if ($attendee->status === 'cancelled') {
            $attendee->update(['status' => 'registered', 'learning_enrolment_id' => $enrolment->id]);
        }

        LearningEvent::dispatch('learning.session.registered', $employee, $session, ['session' => $session->title, 'starts_at' => $session->starts_at->toDateTimeString(), 'venue' => $session->venue ?? $session->meeting_url]);

        return $attendee;
    }

    /** @param  array<int, string>  $statuses  employee id => attended | absent */
    public function markAttendance(TrainingSession $session, array $statuses, ?User $actor = null): int
    {
        $marked = 0;

        foreach ($session->attendees()->with('enrolment')->get() as $attendee) {
            $status = $statuses[$attendee->employee_id] ?? null;
            if (! in_array($status, ['attended', 'absent'], true)) {
                continue;
            }
            $attendee->update(['status' => $status]);
            $marked++;

            if ($status === 'attended' && $attendee->enrolment?->isOpen()) {
                $course = $session->course()->with('assessment')->firstOrFail();
                $this->learning->start($attendee->enrolment);
                $attendee->enrolment->update(['progress' => 100]);
                if ($course->assessment === null) {
                    $this->learning->complete($attendee->enrolment, null, $actor);
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
