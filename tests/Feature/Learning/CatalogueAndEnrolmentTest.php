<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningCompletion;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\Learning\Models\LearningProgram;
use App\Domain\Learning\Models\TrainingSession;
use App\Domain\Learning\Services\Catalogue;
use App\Domain\Learning\Services\Completions;
use App\Domain\Learning\Services\Learning;
use App\Domain\Learning\Services\LearningPaths;
use App\Domain\Learning\Services\Programs;
use App\Domain\Learning\Services\TrainingSessions;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/LearningTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->publisher = tenantUser($this->tenant, ['learning.publish', 'learning.view']);
    $this->actingAs($this->admin);
    $this->learning = app(Learning::class);
    $this->catalogue = app(Catalogue::class);
    $this->manager = activeEmployee(null, ['learning.learn', 'learning.assign', 'learning.approve', 'learning.team', 'task.view']);
    $this->employee = activeEmployee($this->manager, ['learning.learn', 'task.view']);
    $this->stranger = activeEmployee(null, ['learning.learn', 'learning.assign', 'learning.approve']);
});

function draftCourse(array $overrides = []): Course
{
    $course = Course::create(array_merge(['title' => 'Workplace safety', 'code' => 'SAFETY', 'type' => 'elearning', 'category' => 'safety', 'delivery_mode' => 'self_paced', 'duration_minutes' => 90, 'validity_months' => 12, 'status' => 'draft'], $overrides));
    $course->modules()->create(['title' => '2026 curriculum', 'type' => 'text', 'content' => '...', 'sort_order' => 10]);

    return $course->refresh();
}

it('publishes immutable course versions through second-person approval and keeps history on later versions', function () {
    $course = draftCourse();

    expect(fn () => $this->catalogue->publish($course, $this->admin))->toThrow(RuntimeException::class, 'approved by a second person');
    $this->catalogue->submitForApproval($course, $this->admin);
    expect(fn () => $this->catalogue->approve($course->refresh(), $this->manager->user))->toThrow(RuntimeException::class, 'needs learning.publish');

    $submitter = tenantUser($this->tenant, ['learning.publish', 'learning.manage']);
    $other = Course::create(['title' => 'Other', 'code' => 'OTHER', 'type' => 'elearning', 'status' => 'draft']);
    $this->catalogue->submitForApproval($other, $submitter);
    expect(fn () => $this->catalogue->approve($other->refresh(), $submitter))->toThrow(RuntimeException::class, 'cannot approve it');

    $this->catalogue->approve($course->refresh(), $this->publisher);
    $v1 = $this->catalogue->publish($course->refresh(), $this->admin);
    expect($course->refresh()->status)->toBe('published')->and($v1->version)->toBe(1)->and($v1->curriculum[0]['title'])->toBe('2026 curriculum');

    $enrolment = $this->learning->enrol($this->employee, $course);
    $this->learning->complete($enrolment->refresh());
    $completion = LearningCompletion::query()->where('learning_enrolment_id', $enrolment->id)->sole();
    expect($completion->course_version_id)->toBe($v1->id)->and($course->refresh()->status)->toBe('active');

    // 2027 curriculum: a new version; the 2026 completion still shows version 1.
    $course->modules()->first()->update(['title' => '2027 curriculum']);
    $this->catalogue->submitForApproval($course->refresh(), $this->admin);
    $this->catalogue->approve($course->refresh(), $this->publisher);
    $v2 = $this->catalogue->publish($course->refresh(), $this->admin);

    expect($v2->version)->toBe(2)->and($v2->curriculum[0]['title'])->toBe('2027 curriculum')
        ->and($completion->refresh()->courseVersion->curriculum[0]['title'])->toBe('2026 curriculum')
        ->and(fn () => $v1->update(['title' => 'Rewritten']))->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $v1->delete())->toThrow(RuntimeException::class, 'never deleted')
        ->and(fn () => $course->refresh()->update(['status' => 'archived']))->toThrow(RuntimeException::class, 'cannot move from published to archived')
        ->and(AuditEvent::query()->where('module', 'learning')->where('metadata->event', 'course_version_published')->count())->toBe(2);

    $this->catalogue->retire($course->refresh(), 'Superseded by the new safety programme', $this->admin);
    expect(fn () => $this->learning->enrol($this->stranger, $course->refresh()))->toThrow(RuntimeException::class, 'not published');
});

it('schedules future courses and refuses circular prerequisites', function () {
    config(['peopleos.learning.require_catalogue_approval' => false]);
    $a = draftCourse(['code' => 'A']);
    $b = draftCourse(['code' => 'B', 'prerequisite_course_ids' => [$a->id]]);
    $this->catalogue->publish($a, $this->admin);
    $this->catalogue->publish($b, $this->admin, '2026-10-15');
    expect($b->refresh()->status)->toBe('scheduled');

    $a->update(['prerequisite_course_ids' => [$b->id]]);
    expect(fn () => $this->catalogue->assertPrerequisitesAcyclic($a->refresh()))->toThrow(RuntimeException::class, 'circular');
    $a->update(['prerequisite_course_ids' => null]);

    expect($this->catalogue->releaseScheduled('2026-10-15'))->toBe(1)->and($b->refresh()->status)->toBe('published');

    // Prerequisites gate starting the learning, not enrolling in it.
    $this->travelTo('2026-10-16 09:00:00');
    $enrolment = $this->learning->enrol($this->employee, $b->refresh());
    expect(fn () => $this->learning->start($enrolment))->toThrow(RuntimeException::class, 'prerequisites first: A');
    $this->learning->complete($this->learning->enrol($this->employee, $a->refresh()));
    expect($this->learning->start($enrolment->refresh())->status)->toBe('in_progress');
});

it('versions learning paths with in-path prerequisites and programs with completion rules', function () {
    $intro = publishedCourse(['code' => 'LEAD1', 'title' => 'Leadership foundation', 'validity_months' => null], false);
    $people = publishedCourse(['code' => 'LEAD2', 'title' => 'People management', 'validity_months' => null], false);
    $path = LearningPath::create(['name' => 'Leadership', 'code' => 'LEAD', 'milestones' => [['title' => 'Leadership certification', 'after_position' => 2]]]);
    $path->items()->create(['course_id' => $intro->id, 'sort_order' => 10, 'is_required' => true]);
    $second = $path->items()->create(['course_id' => $people->id, 'sort_order' => 20, 'is_required' => true, 'prerequisite_course_ids' => [$intro->id]]);

    $v1 = app(LearningPaths::class)->publish($path, $this->admin);
    expect($v1->items[1]['prerequisite_course_ids'])->toBe([$intro->id])->and($v1->milestones[0]['title'])->toBe('Leadership certification');

    $second->update(['prerequisite_course_ids' => [999999]]);
    expect(fn () => app(LearningPaths::class)->publish($path->refresh(), $this->admin))->toThrow(RuntimeException::class, 'same path');
    $second->update(['prerequisite_course_ids' => [$intro->id]]);

    $program = LearningProgram::create(['code' => 'NEWMGR', 'name' => 'New manager program']);
    $elective = publishedCourse(['code' => 'COACH', 'title' => 'Coaching', 'validity_months' => null], false);
    expect(fn () => app(Programs::class)->publish($program, ['items' => [['type' => 'path', 'id' => $path->id, 'required' => true]], 'min_optional' => 1]))->toThrow(RuntimeException::class, 'has 0');
    $version = app(Programs::class)->publish($program, ['items' => [['type' => 'path', 'id' => $path->id, 'required' => true], ['type' => 'course', 'id' => $elective->id]], 'min_optional' => 1, 'issues_certificate' => true, 'validity_months' => 36]);

    $participant = app(Programs::class)->enrol($program->refresh(), $this->employee, $this->admin);
    $enrolments = LearningEnrolment::query()->where('learning_program_participant_id', $participant->id)->get();
    expect($enrolments)->toHaveCount(3)->and($enrolments->firstWhere('course_id', $people->id)->learning_path_version_id)->toBe($v1->id);

    foreach ([$intro, $people] as $course) {
        $this->learning->complete($enrolments->firstWhere('course_id', $course->id)->refresh());
    }
    expect($participant->refresh()->status)->toBe('enrolled'); // optional elective still missing
    $this->learning->complete($enrolments->firstWhere('course_id', $elective->id)->refresh());

    expect($participant->refresh()->status)->toBe('completed')
        ->and(LearningCompletion::query()->where('learning_program_participant_id', $participant->id)->value('learning_program_version_id'))->toBe($version->id)
        ->and($this->employee->learningCertificates()->where('learning_program_version_id', $version->id)->exists())->toBeTrue();
});

it('routes requests through approval without self-approval and enforces controlled transitions', function () {
    $course = publishedCourse(['code' => 'EXCEL', 'title' => 'Advanced Excel', 'requires_approval' => true, 'allow_self_enrol' => true, 'is_mandatory' => false, 'validity_months' => null], false);

    $request = $this->learning->request($this->employee, $course, 'Needed for month-end reporting', $this->employee->user);
    expect($request->status)->toBe('pending_approval')->and($request->requested_by)->toBe($this->employee->user->id)
        ->and(fn () => $this->learning->decide($request, true, null, $this->employee->user))->toThrow(RuntimeException::class, 'learner or the requester')
        ->and(fn () => $this->learning->decide($request, true, null, $this->stranger->user))->toThrow(RuntimeException::class, 'manager of this employee')
        ->and(fn () => $this->learning->decide($request, false, '', $this->manager->user))->toThrow(RuntimeException::class, 'reason is required');

    $approved = $this->learning->decide($request, true, 'Agreed in 1:1', $this->manager->user);
    expect($approved->status)->toBe('enrolled')->and($approved->approved_by)->toBe($this->manager->user->id)
        ->and(fn () => $this->learning->decide($approved, true, null, $this->manager->user))->toThrow(RuntimeException::class, 'already decided')
        ->and(fn () => $approved->update(['status' => 'expired']))->toThrow(RuntimeException::class, 'cannot move from enrolled to expired')
        ->and(fn () => $approved->update(['status' => 'completed']))->toThrow(RuntimeException::class, 'completion service')
        ->and(fn () => $approved->delete())->toThrow(RuntimeException::class, 'never deleted');

    // Assigned-only courses cannot be self-requested.
    $assignedOnly = publishedCourse(['code' => 'POSH2', 'title' => 'POSH'], false);
    expect(fn () => $this->learning->request($this->employee, $assignedOnly, 'Curious', $this->employee->user))->toThrow(RuntimeException::class, 'cannot be requested');
});

it('assigns to teams as one audited operation and refuses managers outside their scope', function () {
    $course = publishedCourse(['code' => 'INFOSEC', 'title' => 'Information security'], false);
    $peer = activeEmployee($this->manager);

    expect(fn () => $this->learning->assign(['name' => 'Org-wide', 'course_id' => $course->id, 'target_type' => 'population'], $this->manager->user))->toThrow(RuntimeException::class, 'need learning.manage')
        ->and(fn () => $this->learning->assign(['name' => 'Not mine', 'course_id' => $course->id, 'target_type' => 'team', 'target_id' => $this->stranger->id], $this->manager->user))->toThrow(RuntimeException::class, 'they manage');

    $assignment = $this->learning->assign(['name' => 'Team infosec', 'course_id' => $course->id, 'target_type' => 'team', 'target_id' => $this->manager->id, 'priority' => 'high', 'reason' => 'Audit finding', 'is_mandatory' => true, 'due_days' => 14], $this->manager->user);
    $enrolments = LearningEnrolment::query()->where('learning_assignment_id', $assignment->id)->get();

    expect($enrolments->pluck('employee_id')->sort()->values()->all())->toBe(collect([$this->employee->id, $peer->id])->sort()->values()->all())
        ->and($enrolments->pluck('status')->unique()->all())->toBe(['assigned'])
        ->and($enrolments->first()->assignment_version)->toBe(1)
        ->and($assignment->operation_id)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', 'BULK_OPERATION')->where('operation_id', $assignment->operation_id)->exists())->toBeTrue();

    $assignment->update(['due_days' => 30]);
    expect($assignment->refresh()->version)->toBe(2);

    expect($this->learning->cancelAssignment($assignment, 'Training moved to Q1', $this->admin))->toBe(2)
        ->and(LearningEnrolment::query()->where('learning_assignment_id', $assignment->id)->pluck('status')->unique()->all())->toBe(['cancelled'])
        ->and(fn () => $assignment->refresh()->update(['name' => 'x']))->toThrow(RuntimeException::class, 'read-only');
});

it('waitlists past capacity and promotes the first waiting learner when a seat frees', function () {
    $course = publishedCourse(['code' => 'FIRST', 'title' => 'First aid', 'type' => 'classroom', 'validity_months' => 24], false);
    $session = TrainingSession::create(['course_id' => $course->id, 'title' => 'First aid', 'mode' => 'classroom', 'starts_at' => '2026-10-05 10:00:00', 'ends_at' => '2026-10-05 13:00:00', 'capacity' => 1]);
    $sessions = app(TrainingSessions::class);
    $second = activeEmployee();
    $third = activeEmployee();

    $seat = $sessions->register($session, $this->employee);
    $wait1 = $sessions->register($session, $second);
    $wait2 = $sessions->register($session, $third);
    expect([$seat->status, $wait1->status, $wait1->waitlist_position, $wait2->waitlist_position])->toBe(['registered', 'waitlisted', 1, 2])
        ->and($sessions->register($session, $this->employee)->id)->toBe($seat->id);

    $promoted = $sessions->cancel($seat, 'Travelling', $this->admin);
    expect($promoted->id)->toBe($wait1->id)->and($promoted->status)->toBe('registered')
        ->and($wait1->enrolment->refresh()->status)->toBe('enrolled')
        ->and($seat->enrolment->refresh()->status)->toBe('cancelled')
        ->and($session->refresh()->seatsLeft())->toBe(0);
});

it('keeps completions immutable and corrects them with a new record', function () {
    $course = publishedCourse(['code' => 'GDPR', 'title' => 'Data protection', 'validity_months' => null], false);
    $enrolment = $this->learning->enrol($this->employee, $course);
    $this->learning->updateProgress($enrolment, 100, $this->admin);
    expect($enrolment->refresh()->status)->toBe('in_progress'); // 100% progress is not completion

    $completion = app(Completions::class)->finalize($enrolment->refresh(), 72.0, $this->admin, ['grade' => 'pass']);
    expect(fn () => app(Completions::class)->finalize($enrolment->refresh(), 80.0, $this->admin))->toThrow(RuntimeException::class, 'already completed')
        ->and(fn () => $completion->update(['score' => 99]))->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $completion->delete())->toThrow(RuntimeException::class, 'never deleted')
        ->and(fn () => app(Completions::class)->correct($completion, ['score' => 82.0], 'Re-marked', $this->employee->user))->toThrow(RuntimeException::class, 'learning.manage');

    $correction = app(Completions::class)->correct($completion, ['score' => 82.0, 'grade' => 'merit'], 'Question 4 re-marked', $this->admin);
    expect($correction->sequence)->toBe(2)->and($correction->corrects_completion_id)->toBe($completion->id)
        ->and((float) $correction->score)->toBe(82.0)->and((float) $completion->refresh()->score)->toBe(72.0)
        ->and($completion->status)->toBe('superseded')
        ->and(fn () => app(Completions::class)->correct($completion, ['score' => 90.0], 'Again', $this->admin))->toThrow(RuntimeException::class, 'current completion record')
        ->and(AuditEvent::query()->where('metadata->event', 'completion_corrected')->exists())->toBeTrue();
});
