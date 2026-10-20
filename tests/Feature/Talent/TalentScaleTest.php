<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Career\Services\CareerArchitecture;
use App\Domain\Career\Services\CareerProfiles;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Designation;
use App\Domain\People\Models\Skill;
use App\Domain\Succession\Services\CriticalPositions;
use App\Domain\Succession\Services\Readiness;
use App\Domain\Succession\Services\SuccessionPlans;
use App\Domain\Talent\Models\TalentPool;
use App\Domain\Talent\Models\TalentPoolMembership;
use App\Domain\Talent\Models\TalentReviewItem;
use App\Domain\Talent\Services\TalentAnalytics;
use App\Domain\Talent\Services\TalentPools;
use App\Domain\Talent\Services\TalentReviews;
use App\Filament\Pages\TeamCareer;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/../Learning/LearningTestHelpers.php';

/*
 | Phase 9 §51 scale checks (SQLite): populations are processed in chunks as one audited operation,
 | and dashboard read paths run a constant number of queries whatever the population (no N+1).
 */

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->assessment = ['criticality' => 'high', 'business_impact' => 'high', 'scarcity' => 'medium', 'replacement_difficulty' => 'high', 'operational_dependency' => 'medium', 'reason' => 'Scale'];
    $this->count = function (callable $callback): int {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $callback();

        return $queries;
    };
    // Critical positions, each with an open plan, two successors and a readiness label.
    $this->seedPositions = function (int $from, int $to, array $successors): void {
        foreach (range($from, $to) as $i) {
            $position = app(CriticalPositions::class)->designate(Designation::query()->create(['name' => "Role {$i}", 'code' => "ROLE{$i}"]), null, "Role {$i}", $this->assessment, 12, $this->admin);
            $plan = app(SuccessionPlans::class)->create($position, [], $this->admin);
            foreach ($successors as $employee) {
                $successor = app(SuccessionPlans::class)->addSuccessor($plan, $employee, null, null, null, $this->admin);
                app(Readiness::class)->assess($employee, $position->id, null, $i % 2 ? 'ready_now' : '1_2_years', 'Scale', null, $this->admin, $successor);
            }
        }
    };
});

it('adds a large talent pool and a large review population in chunks, each as one audited operation', function () {
    // Organisation scope is fail-closed: an employee without a current position is outside every scope.
    $company = Company::query()->first() ?? Company::factory()->create();
    Employee::factory()->count(220)->create()->each(fn (Employee $e) => EmployeePosition::query()->create(['employee_id' => $e->id, 'company_id' => $company->id, 'change_type' => 'hire', 'effective_from' => '2025-01-01']));
    $ids = Employee::query()->employed()->pluck('id')->all();
    $pool = TalentPool::create(['code' => 'LARGE', 'name' => 'Large pool']);

    $queries = ($this->count)(fn () => $this->result = app(TalentPools::class)->addMany($pool, $ids, 'Annual nomination', $this->admin));
    expect($this->result['skipped'])->toBe([])->and($this->result['added'])->toBe(count($ids))
        ->and(TalentPoolMembership::query()->where('talent_pool_id', $pool->id)->count())->toBe(count($ids))
        ->and(AuditEvent::query()->where('action', 'BULK_OPERATION')->where('module', 'talent')->latest('occurred_at')->value('metadata')['success_count'] ?? null)->toBe(count($ids))
        ->and($queries / count($ids))->toBeLessThan(40);

    $session = app(TalentReviews::class)->create('Large review', null, [], $this->admin);
    expect(app(TalentReviews::class)->addEmployees($session, $ids, $this->admin))->toBe(count($ids))
        ->and(TalentReviewItem::query()->where('talent_review_session_id', $session->id)->count())->toBe(count($ids))
        ->and(app(TalentReviews::class)->addEmployees($session, $ids, $this->admin))->toBe(0); // idempotent

    config(['peopleos.talent.analytics_min_group' => 5]);
    expect(app(TalentAnalytics::class)->summary()['pools'][0])->toBe(['pool' => 'Large pool', 'count' => count($ids), 'suppressed' => false]);
});

it('keeps the critical-position dashboard and succession coverage at a constant query count', function () {
    $successors = [activeEmployee(), activeEmployee()];
    ($this->seedPositions)(1, 4, $successors);
    $small = ($this->count)(fn () => app(TalentAnalytics::class)->summary());

    ($this->seedPositions)(5, 40, $successors);
    $large = ($this->count)(fn () => $this->summary = app(TalentAnalytics::class)->summary());

    expect($this->summary['critical_positions']['active'])->toBe(40)
        ->and($this->summary['succession']['with_successors'])->toBe(40)
        ->and($this->summary['succession']['with_ready_now'])->toBe(20)
        ->and($large)->toBe($small);
});

it('keeps the team career dashboard at a constant query count as the team grows', function () {
    $manager = activeEmployee(null, ['career.self', 'career.team', 'succession.team']);
    $team = [activeEmployee($manager, ['career.self']), activeEmployee($manager, ['career.self'])];
    $this->actingAs($manager->user);
    $page = new TeamCareer;
    $small = ($this->count)(fn () => [$page->members(), $page->succession()]);

    $this->actingAs($this->admin);
    foreach (range(1, 18) as $i) {
        $team[] = $member = activeEmployee($manager, ['career.self']);
        app(CareerProfiles::class)->createGoal($member, "Goal {$i}", 'skill', [], $member->user);
    }
    ($this->seedPositions)(1, 3, array_slice($team, 0, 6));
    $this->actingAs($manager->user);
    $large = ($this->count)(fn () => $this->members = (new TeamCareer)->members());
    $succession = ($this->count)(fn () => $this->succession = (new TeamCareer)->succession());

    expect($this->members)->toHaveCount(20)
        ->and($this->succession)->toHaveCount(18)
        ->and($large)->toBeLessThanOrEqual($small) // members: constant
        ->and($succession)->toBeLessThan(10);       // succession: one query plus eager loads
});

it('computes successor skill gaps per position requirement in chunks, not per-successor requirement lookups', function () {
    $skill = Skill::create(['name' => 'Plant operations', 'code' => 'PLANTOPS']);
    $successors = collect(range(1, 12))->map(fn () => activeEmployee())->all();
    ($this->seedPositions)(1, 2, $successors);
    $certificate = publishedCourse(['code' => 'PLANTCERT', 'title' => 'Plant safety certificate', 'validity_months' => 24], false);
    foreach ([1, 2] as $i) {
        app(CareerArchitecture::class)->publishRequirements(Designation::query()->where('code', "ROLE{$i}")->sole(), null, ['skills' => [['skill_id' => $skill->id, 'level' => 3]], 'certifications' => [['course_id' => $certificate->id]]], '2026-01-01', $this->admin);
    }
    config(['peopleos.talent.analytics_min_group' => 5]);

    $requirementLookups = 0;
    DB::listen(function ($query) use (&$requirementLookups) {
        if (str_contains($query->sql, 'from "role_requirement_versions"')) {
            $requirementLookups++;
        }
    });
    $gaps = app(TalentAnalytics::class)->successorGaps();

    expect($gaps)->toBe([['key' => 'Plant operations', 'count' => 24, 'suppressed' => false]]) // 12 successors on each of 2 plans
        ->and(app(TalentAnalytics::class)->successorCertificationGaps())->toBe([['key' => 'Plant safety certificate', 'count' => 24, 'suppressed' => false]])
        ->and($requirementLookups)->toBeLessThanOrEqual(4); // per position (role-wide lookup + unit fallback), not per successor
});
