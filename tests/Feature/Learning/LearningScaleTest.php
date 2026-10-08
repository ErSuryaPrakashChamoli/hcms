<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningCertificate;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Services\Certificates;
use App\Domain\Learning\Services\Learning;
use App\Domain\Learning\Services\LearningAnalytics;
use App\Domain\People\Models\Skill;
use App\Domain\Skills\Models\EmployeeSkill;
use App\Domain\Skills\Services\SkillScales;
use App\Filament\Pages\TeamLearning;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/LearningTestHelpers.php';

/*
 | Phase 8 §60 scale checks (SQLite): populations are processed in chunks and read paths run a
 | constant number of queries whatever the population or catalogue size (no N+1).
 */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->count = function (callable $callback): int {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $callback();

        return $queries;
    };
});

it('assigns a population of 240 employees in chunks as one audited bulk operation', function () {
    Employee::factory()->count(240)->create();
    config(['peopleos.learning.assignment_chunk' => 50]);
    $course = publishedCourse(['code' => 'CONDUCT', 'title' => 'Code of conduct'], false);

    $queries = ($this->count)(fn () => $this->assignment = app(Learning::class)->assign(['name' => 'Everyone', 'course_id' => $course->id, 'target_type' => 'population', 'is_mandatory' => true], $this->admin));

    $total = Employee::query()->employed()->count();
    expect(LearningEnrolment::query()->where('learning_assignment_id', $this->assignment->id)->count())->toBe($total)
        ->and(AuditEvent::query()->where('action', 'BULK_OPERATION')->where('operation_id', $this->assignment->operation_id)->value('metadata')['success_count'] ?? null)->toBe($total)
        ->and($queries / $total)->toBeLessThan(40); // bounded per employee — no query grows with the population

    // A second run adds nothing (idempotent per assignment).
    expect(app(Learning::class)->applyAssignment($this->assignment->refresh())->count())->toBe(0);
});

it('serves a 300-course catalogue page and the team dashboard with a constant number of queries', function () {
    foreach (range(1, 300) as $i) {
        Course::create(['title' => "Course {$i}", 'code' => "C{$i}", 'type' => 'elearning', 'status' => 'published', 'category' => 'technical']);
    }
    $key = app(ApiKeys::class)->issue('BI', ['learning.read']);
    $this->withHeader('X-Api-Key', $key['plaintext'])->getJson('/api/v1/learning/catalogue?per_page=1')->assertOk(); // warm-up (one-time key and route caching)
    $small = ($this->count)(fn () => $this->withHeader('X-Api-Key', $key['plaintext'])->getJson('/api/v1/learning/catalogue?per_page=10')->assertOk());
    $large = ($this->count)(fn () => $this->withHeader('X-Api-Key', $key['plaintext'])->getJson('/api/v1/learning/catalogue?per_page=200')->assertOk()->assertJsonCount(200, 'data'));
    expect($large)->toBe($small);

    $manager = activeEmployee(null, ['learning.team', 'learning.assign']);
    $course = Course::query()->first();
    foreach (Employee::factory()->count(60)->create() as $report) {
        ReportingRelationship::query()->create(['employee_id' => $report->id, 'manager_id' => $manager->id, 'type' => 'line', 'is_primary' => true, 'effective_from' => '2026-01-01']);
        LearningEnrolment::query()->create(['employee_id' => $report->id, 'course_id' => $course->id, 'status' => 'overdue', 'is_mandatory' => true, 'due_on' => '2026-09-01']);
    }
    $this->actingAs($manager->user);
    $page = new TeamLearning;
    $queries = ($this->count)(fn () => expect($page->members())->toHaveCount(60));
    expect($queries)->toBeLessThan(15);
});

it('aggregates mandatory compliance, skill gaps and certificate expiry without per-employee queries', function () {
    $employees = Employee::factory()->count(150)->create();
    $course = publishedCourse(['code' => 'POSH9', 'title' => 'POSH', 'validity_months' => 12], false);
    $version = app(SkillScales::class)->defaultVersion();
    $skill = Skill::create(['name' => 'SQL', 'code' => 'SQL']);
    foreach ($employees as $i => $employee) {
        LearningEnrolment::query()->create(['employee_id' => $employee->id, 'course_id' => $course->id, 'status' => $i % 3 === 0 ? 'overdue' : 'enrolled', 'is_mandatory' => true]);
        EmployeeSkill::query()->create(['employee_id' => $employee->id, 'skill_id' => $skill->id, 'skill_scale_version_id' => $version->id, 'current_level' => 1, 'target_level' => 3, 'source' => 'manager', 'valid_from' => '2026-01-01']);
        LearningCertificate::query()->create(['employee_id' => $employee->id, 'course_id' => $course->id, 'number' => "N{$i}", 'issued_on' => '2025-10-10', 'expires_on' => '2026-10-10', 'status' => 'valid']);
    }
    $analytics = app(LearningAnalytics::class);

    $mandatoryQueries = ($this->count)(fn () => expect($analytics->mandatoryReport()[0])->toMatchArray(['course' => 'POSH9', 'assigned' => 150, 'overdue' => 50]));
    $gapQueries = ($this->count)(fn () => expect($analytics->skillGaps()[0])->toMatchArray(['employees' => 150, 'average_gap' => 2.0]));
    expect($mandatoryQueries)->toBeLessThan(5)->and($gapQueries)->toBeLessThan(5);

    // Expiry runs in chunks: every certificate inside the notice window moves to expiring once.
    expect(app(Certificates::class)->tick()['expiring'])->toBe(150)
        ->and(LearningCertificate::query()->where('status', 'expiring')->count())->toBe(150)
        ->and(app(Certificates::class)->tick()['expiring'])->toBe(0);
});
