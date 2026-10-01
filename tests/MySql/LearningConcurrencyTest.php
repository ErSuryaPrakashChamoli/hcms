<?php

use App\Domain\Development\Models\DevelopmentPlan;
use App\Domain\Development\Services\DevelopmentPlans;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\CourseVersion;
use App\Domain\Learning\Models\LearningCertificate;
use App\Domain\Learning\Models\LearningCompletion;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Models\TrainingSession;
use App\Domain\Learning\Models\TrainingSessionAttendee;
use App\Domain\Learning\Services\Catalogue;
use App\Domain\Learning\Services\Completions;
use App\Domain\Learning\Services\Learning;
use App\Domain\Learning\Services\TrainingSessions;
use App\Domain\People\Models\Skill;
use App\Domain\Skills\Models\EmployeeSkill;
use App\Domain\Skills\Models\SkillAssessment;
use App\Domain\Skills\Services\SkillAssessments;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Feature/Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Feature/Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Feature/Learning/LearningTestHelpers.php';

/*
 | Phase 8 §49: real concurrency on MySQL. Each race forks processes that hit the same row at the
 | same moment, with a pause between "read the state" and "write" so a missing row lock would let
 | both through. SQLite tests cannot show this; these run only when PEOPLEOS_MYSQL_CONCURRENCY_DB
 | names a disposable database (its name must contain "concurrency"). It is migrated fresh here.
 */

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_CONCURRENCY_DB', '');
    if ($database === '' || ! str_contains($database, 'concurrency') || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_CONCURRENCY_DB to a disposable MySQL database (name containing "concurrency") and enable pcntl.');
    }
    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'concurrency', 'queue.default' => 'sync']);
    DB::purge('concurrency');
    if (! ($GLOBALS['peopleos_concurrency_migrated'] ?? false)) {
        Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
        $GLOBALS['peopleos_concurrency_migrated'] = true;
    }
    $this->tenant = provisionTenant('Race '.uniqid());
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
});

/**
 * Run each callback in its own forked process, all starting together. Each child opens its own
 * MySQL connection; results come back through temp files ('ok' or the exception message).
 *
 * @param  list<callable>  $callbacks
 * @return list<string>
 */
function race(array $callbacks, float $pause = 0.4): array
{
    DB::disconnect();
    $start = microtime(true) + 0.5;
    $files = [];
    $pids = [];
    foreach ($callbacks as $i => $callback) {
        $files[$i] = tempnam(sys_get_temp_dir(), 'race');
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::purge();
            // Widen the window between reading state and writing it: without a row lock, both writers pass.
            foreach (['eloquent.creating: '.TrainingSessionAttendee::class, 'eloquent.creating: '.LearningCompletion::class, 'eloquent.creating: '.CourseVersion::class, 'eloquent.updating: '.SkillAssessment::class, 'eloquent.updating: '.DevelopmentPlan::class] as $event) {
                Event::listen($event, fn () => usleep((int) ($pause * 1_000_000)));
            }
            while (microtime(true) < $start) {
                usleep(1000);
            }
            try {
                $callback();
                file_put_contents($files[$i], 'ok');
            } catch (Throwable $e) {
                file_put_contents($files[$i], $e->getMessage());
            }
            posix_kill(getmypid(), SIGKILL); // skip shutdown handlers inherited from the test runner
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
    DB::purge();

    return array_map(function ($file) {
        $result = (string) file_get_contents($file);
        unlink($file);

        return $result;
    }, $files);
}

it('lets only one of two simultaneous registrations take the last seat; the other is waitlisted', function () {
    $course = publishedCourse(['code' => 'LAST', 'title' => 'Last seat', 'type' => 'classroom', 'validity_months' => null], false);
    app(Catalogue::class)->ensureVersion($course); // isolate the seat race from first-use versioning
    $session = TrainingSession::create(['course_id' => $course->id, 'title' => 'Last seat', 'mode' => 'classroom', 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(2), 'capacity' => 1]);
    [$a, $b] = [activeEmployee(), activeEmployee()];

    $results = race([
        fn () => app(TrainingSessions::class)->register(TrainingSession::query()->findOrFail($session->id), $a),
        fn () => app(TrainingSessions::class)->register(TrainingSession::query()->findOrFail($session->id), $b),
    ]);

    expect($results)->toBe(['ok', 'ok'])
        ->and(TrainingSessionAttendee::query()->where('training_session_id', $session->id)->where('status', 'registered')->count())->toBe(1)
        ->and(TrainingSessionAttendee::query()->where('training_session_id', $session->id)->where('status', 'waitlisted')->count())->toBe(1);
});

it('pins one course version when two first enrolments on a legacy course race', function () {
    $course = publishedCourse(['code' => 'FIRSTUSE', 'title' => 'First use', 'validity_months' => null], false);
    [$a, $b] = [activeEmployee(), activeEmployee()];

    $results = race([
        fn () => app(Learning::class)->enrol($a, Course::query()->findOrFail($course->id)),
        fn () => app(Learning::class)->enrol($b, Course::query()->findOrFail($course->id)),
    ]);

    expect($results)->toBe(['ok', 'ok'])
        ->and(CourseVersion::query()->where('course_id', $course->id)->count())->toBe(1)
        ->and(LearningEnrolment::query()->where('course_id', $course->id)->pluck('course_version_id')->unique()->count())->toBe(1);
});

it('finalizes a completion once and issues one certificate when two people complete at the same time', function () {
    $employee = activeEmployee();
    $enrolment = app(Learning::class)->enrol($employee, publishedCourse(['code' => 'TWICE', 'title' => 'Twice', 'validity_months' => 12], false));

    $results = race([
        fn () => app(Completions::class)->finalize(LearningEnrolment::query()->findOrFail($enrolment->id), 80.0, $this->admin),
        fn () => app(Completions::class)->finalize(LearningEnrolment::query()->findOrFail($enrolment->id), 90.0, $this->admin),
    ]);

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('already completed')
        ->and(LearningCompletion::query()->where('learning_enrolment_id', $enrolment->id)->count())->toBe(1)
        ->and(LearningCertificate::query()->where('learning_enrolment_id', $enrolment->id)->count())->toBe(1);
});

it('finalizes a skill assessment once and completes a development plan once under concurrency', function () {
    $manager = activeEmployee(null, ['skills.assess', 'development.team']);
    $employee = activeEmployee($manager);
    $skill = Skill::create(['name' => 'Race skill', 'code' => 'RACE']);
    $assessment = app(SkillAssessments::class)->draft($employee, $skill->id, 'manager', ['level' => 2], $manager->user);

    $results = race([
        fn () => app(SkillAssessments::class)->finalize(SkillAssessment::query()->findOrFail($assessment->id), $manager->user),
        fn () => app(SkillAssessments::class)->finalize(SkillAssessment::query()->findOrFail($assessment->id), $manager->user),
    ]);
    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(EmployeeSkill::query()->where('skill_assessment_id', $assessment->id)->count())->toBe(1);

    $plans = app(DevelopmentPlans::class);
    $plan = $plans->create($employee, 'Race plan', [], $manager->user);
    $plans->transition($plan, 'active', null, $manager->user);
    $plan->refresh();
    $results = race([
        fn () => $plans->transition(DevelopmentPlan::query()->findOrFail($plan->id), 'completed', null, $manager->user),
        fn () => $plans->transition(DevelopmentPlan::query()->findOrFail($plan->id), 'on_hold', 'Paused', $manager->user),
    ]);
    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(in_array($plan->refresh()->status, ['completed', 'on_hold'], true))->toBeTrue();
});

it('keeps the audit chain intact after concurrent writes', function () {
    expect(Artisan::call('peopleos:audit:verify'))->toBe(0);
});
