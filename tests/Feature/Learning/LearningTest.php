<?php

use App\Domain\Learning\Models\LearningAssignment;
use App\Domain\Learning\Models\LearningCertificate;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Models\LearningPath;
use App\Domain\Learning\Models\TrainingSession;
use App\Domain\Learning\Services\Assessments;
use App\Domain\Learning\Services\Learning;
use App\Domain\Learning\Services\TrainingSessions;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/LearningTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->learning = app(Learning::class);
    $this->employee = activeEmployee(null, ['learning.learn', 'task.view']);
    $this->course = publishedCourse();
});

it('enrols, tracks module progress, grades the assessment, issues a certificate and expires it', function () {
    $draft = publishedCourse(['code' => 'DRAFT', 'status' => 'draft'], false);
    expect(fn () => $this->learning->enrol($this->employee, $draft))->toThrow(RuntimeException::class, 'not published');

    $enrolment = $this->learning->enrol($this->employee, $this->course, now()->addDays(30));
    expect($enrolment->status)->toBe('enrolled')->and($enrolment->is_mandatory)->toBeTrue()
        ->and($this->learning->enrol($this->employee, $this->course)->id)->toBe($enrolment->id) // idempotent
        ->and($this->employee->user->notifications()->count())->toBe(1);

    [$m1, $m2] = $this->course->modules;
    $this->learning->completeModule($enrolment, $m1);
    expect((float) $enrolment->refresh()->progress)->toBe(50.0)->and($enrolment->status)->toBe('in_progress');
    $this->learning->completeModule($enrolment, $m2);
    expect((float) $enrolment->refresh()->progress)->toBe(100.0)->and($enrolment->status)->toBe('in_progress'); // quiz still pending

    $assessments = app(Assessments::class);
    $fail = $assessments->submit($enrolment, [0 => 0, 1 => 1]); // both wrong
    expect((float) $fail->score)->toBe(0.0)->and($fail->passed)->toBeFalse()->and($enrolment->refresh()->attempts)->toBe(1)->and($enrolment->status)->toBe('in_progress');

    $pass = $assessments->submit($enrolment, [0 => 1, 1 => 1]); // one right of two = 50% = pass mark
    expect($pass->passed)->toBeTrue()->and($enrolment->refresh()->status)->toBe('completed')
        ->and((float) $enrolment->score)->toBe(50.0)
        ->and($enrolment->expires_on->toDateString())->toBe('2027-09-21')
        ->and(EmployeeTimelineEntry::query()->where('employee_id', $this->employee->id)->where('category', 'learning')->exists())->toBeTrue();
    expect(fn () => $assessments->submit($enrolment, []))->toThrow(RuntimeException::class, 'closed');

    $certificate = LearningCertificate::query()->where('employee_id', $this->employee->id)->first();
    expect($certificate->number)->toBe('CERT-POSH-202609-'.$this->employee->employee_code)->and($certificate->status)->toBe('valid');

    // 30 days before expiry: expiring notice; after expiry: expired and the enrolment flips too.
    $this->travelTo('2027-08-22 09:00:00');
    expect($this->learning->tick()['expiring'])->toBe(1)->and($certificate->refresh()->status)->toBe('expiring');
    $this->travelTo('2027-09-22 09:00:00');
    expect($this->learning->tick()['expired'])->toBe(1)->and($certificate->refresh()->status)->toBe('expired')->and($enrolment->refresh()->status)->toBe('expired');
});

it('fails the enrolment when attempts run out and marks overdue enrolments daily', function () {
    $enrolment = $this->learning->enrol($this->employee, $this->course, now()->addDays(3));
    $assessments = app(Assessments::class);
    $assessments->submit($enrolment, [0 => 0, 1 => 1]);
    $assessments->submit($enrolment, [0 => 0, 1 => 1]);
    expect($enrolment->refresh()->status)->toBe('failed')->and($enrolment->attempts)->toBe(2);

    $other = activeEmployee();
    $late = $this->learning->enrol($other, $this->course, now()->addDays(3));
    $this->travelTo('2026-09-25 09:00:00');
    $result = $this->learning->tick();
    expect($result['overdue'])->toBe(1)->and($late->refresh()->status)->toBe('overdue')
        ->and($other->user->notifications()->get()->pluck('data.title')->first())->toContain('Overdue');
});

it('applies rule-based and recurring assignments, and paths enrol every course', function () {
    $second = publishedCourse(['code' => 'SEC', 'title' => 'Security basics', 'is_mandatory' => false, 'validity_months' => null], false);
    $path = LearningPath::create(['name' => 'New joiner', 'code' => 'NJ']);
    $path->items()->create(['course_id' => $this->course->id, 'sort_order' => 10, 'is_required' => true]);
    $path->items()->create(['course_id' => $second->id, 'sort_order' => 20, 'is_required' => false]);

    $probation = activeEmployee();
    forceLifecycle($probation, 'probation');

    $assignment = LearningAssignment::create(['name' => 'Everyone on probation', 'learning_path_id' => $path->id, 'conditions' => [['field' => 'lifecycle_state', 'operator' => 'equals', 'value' => 'probation']], 'due_days' => 14, 'recur_months' => 12, 'is_mandatory' => true]);
    $enrolled = $this->learning->applyAssignment($assignment);

    expect($enrolled->all())->toBe([$probation->id])
        ->and(LearningEnrolment::query()->where('employee_id', $probation->id)->count())->toBe(2)
        ->and(LearningEnrolment::query()->where('employee_id', $probation->id)->where('course_id', $second->id)->first()->is_mandatory)->toBeTrue()
        ->and(LearningEnrolment::query()->where('employee_id', $probation->id)->first()->due_on->toDateString())->toBe('2026-10-05')
        ->and($this->learning->applyAssignment($assignment)->count())->toBe(0); // already covered

    // Completing the required course, then a year later the recurring assignment enrols again.
    $enrolment = LearningEnrolment::query()->where('employee_id', $probation->id)->where('course_id', $this->course->id)->first();
    foreach ($this->course->modules as $m) {
        $this->learning->completeModule($enrolment, $m);
    }
    app(Assessments::class)->submit($enrolment, [0 => 1, 1 => 0]);
    $other = LearningEnrolment::query()->where('employee_id', $probation->id)->where('course_id', $second->id)->first();
    $this->learning->withdraw($other, 'Not needed');
    expect($this->learning->applyAssignment($assignment)->count())->toBe(0);

    $this->travelTo('2027-10-01 09:00:00');
    expect($this->learning->tick()['assigned'])->toBe(1)
        ->and(LearningEnrolment::query()->where('employee_id', $probation->id)->where('course_id', $this->course->id)->count())->toBe(2);

    // A one-employee assignment ignores conditions.
    $direct = LearningAssignment::create(['name' => 'Just one', 'course_id' => $second->id, 'employee_id' => $this->employee->id, 'due_days' => 7]);
    expect($this->learning->applyAssignment($direct)->all())->toBe([$this->employee->id]);
});

it('registers for instructor-led sessions and completes the enrolment on attendance', function () {
    $classroom = publishedCourse(['code' => 'FIRE', 'title' => 'Fire safety', 'type' => 'classroom', 'validity_months' => 24], false);
    $session = TrainingSession::create(['course_id' => $classroom->id, 'title' => 'Fire drill', 'mode' => 'classroom', 'trainer_name' => 'Warden', 'starts_at' => '2026-10-02 10:00:00', 'ends_at' => '2026-10-02 12:00:00', 'venue' => 'Hall A', 'capacity' => 1]);
    $sessions = app(TrainingSessions::class);

    $attendee = $sessions->register($session, $this->employee);
    expect($attendee->status)->toBe('registered')
        ->and($attendee->enrolment->due_on->toDateString())->toBe('2026-10-02')
        ->and($session->seatsLeft())->toBe(0);
    // Phase 8: a full session waitlists the next learner instead of refusing them.
    $waiting = $sessions->register($session, activeEmployee());
    expect($waiting->status)->toBe('waitlisted')->and($waiting->waitlist_position)->toBe(1)
        ->and($waiting->enrolment->status)->toBe('waitlisted');

    $sessions->markAttendance($session, [$this->employee->id => 'attended']);
    expect($attendee->refresh()->status)->toBe('attended')
        ->and($attendee->enrolment->refresh()->status)->toBe('completed')
        ->and(LearningCertificate::query()->where('course_id', $classroom->id)->exists())->toBeTrue();
});
