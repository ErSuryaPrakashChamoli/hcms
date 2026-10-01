<?php

use App\Domain\Career\Services\CareerArchitecture;
use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organisation\Models\Designation;
use App\Domain\People\Models\Skill;
use App\Domain\Performance\Models\CareerPath;
use App\Domain\Succession\Services\CriticalPositions;
use App\Domain\Succession\Services\Readiness;
use App\Domain\Succession\Services\SuccessionPlans;
use App\Domain\Workforce\Jobs\SendWorkforceReminders;
use App\Domain\Workforce\Models\WorkforceReminderLog;
use App\Domain\Workforce\Services\Positions;
use App\Domain\Workforce\Services\WorkforceIntegrations;
use App\Domain\Workforce\Services\WorkforcePlans;
use App\Domain\Workforce\Services\WorkforceReminders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/WorkforceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->creator = tenantUser($this->tenant, ['workforce.view', 'workforce.manage', 'workforce.plan']);
    $this->approver = tenantUser($this->tenant, ['workforce.view', 'workforce.approve', 'workforce.manage', 'workforce.review']);
    $this->org = workforceOrg();
});

it('reads career, requirements and succession for a position and leaves those modules untouched by workforce actions', function () {
    $manager = Designation::query()->create(['name' => 'Plant Manager', 'code' => 'PM']);
    $director = Designation::query()->create(['name' => 'Operations Director', 'code' => 'OD']);
    $path = CareerPath::create(['name' => 'Operations ladder', 'code' => 'ops']);
    $path->steps()->create(['designation_id' => $manager->id, 'sort_order' => 10]);
    $path->steps()->create(['designation_id' => $director->id, 'sort_order' => 20]);
    $skill = Skill::create(['name' => 'Lean operations', 'code' => 'LEAN']);
    app(CareerArchitecture::class)->publishRequirements($manager, null, ['skills' => [['skill_id' => $skill->id, 'level' => 3]], 'min_experience_years' => 5], '2026-01-01', $this->admin);

    $position = openPosition(['code' => 'PM-1', 'designation_id' => $manager->id, 'organisation_node_id' => $this->org['department']->id], $this->creator, $this->approver, '2026-01-01');
    $next = openPosition(['code' => 'OD-1', 'designation_id' => $director->id, 'organisation_node_id' => $this->org['department']->id], $this->creator, $this->approver, '2026-01-01');
    $incumbent = activeEmployee();
    app(AssignPositionAction::class)->handle($incumbent, ['position_id' => $position->id], 'transfer', '2026-02-01', 'Seat');
    $critical = app(CriticalPositions::class)->designate($manager, null, 'Plant Manager', ['criticality' => 'high', 'business_impact' => 'high', 'scarcity' => 'medium', 'replacement_difficulty' => 'high', 'operational_dependency' => 'high', 'reason' => 'Single site'], 12, $this->admin);
    $plan = app(SuccessionPlans::class)->create($critical, [], $this->admin);
    $candidate = activeEmployee();
    $successor = app(SuccessionPlans::class)->addSuccessor($plan, $candidate, null, null, null, $this->admin);
    app(Readiness::class)->assess($candidate, $critical->id, null, 'ready_now', 'Ran the night shift', null, $this->admin, $successor);

    $integrations = app(WorkforceIntegrations::class);
    expect($integrations->requirements($position))->toMatchArray(['version' => 1, 'scope' => 'role-wide', 'skills' => 1, 'min_experience_years' => 5.0])
        ->and($integrations->succession($position))->toMatchArray(['critical_position' => 'Plant Manager', 'criticality' => 'high', 'plan_status' => 'draft', 'successors' => 1, 'ready_now' => 1])
        ->and($integrations->careerPaths($position))->toBe([['path' => 'Operations ladder', 'track' => null, 'next' => ['Operations Director']]])
        ->and(collect($integrations->nextPositions($position))->pluck('code')->all())->toBe(['OD-1']);

    $tables = ['career_paths', 'career_path_steps', 'role_requirement_versions', 'critical_positions', 'succession_plans', 'successors', 'readiness_assessments', 'talent_pool_memberships', 'talent_profiles', 'career_goals', 'development_plans', 'development_plan_items', 'learning_enrolments', 'goals', 'appraisals', 'employee_skills'];
    $fingerprint = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count().'|'.(DB::getSchemaBuilder()->hasColumn($t, 'updated_at') ? DB::table($t)->max('updated_at') : '')])->all();
    $before = $fingerprint();

    $positions = app(Positions::class);
    $positions->change($position, ['title' => 'Plant Manager (North)'], '2026-11-01', 'Rename', $this->creator);
    $positions->transition($next, 'frozen', 'Hiring freeze', $this->creator);
    app(AssignPositionAction::class)->handle($incumbent, ['vacate_position' => true], 'reassignment', '2026-12-01', 'Moved to projects');
    $wp = app(WorkforcePlans::class)->create(['code' => 'OPS27', 'name' => 'Ops 2027', 'company_id' => $this->org['company']->id, 'period_type' => 'annual', 'period_start' => '2027-01-01', 'period_end' => '2027-12-31'], $this->creator);
    $line = app(WorkforcePlans::class)->addLine($wp->versions()->first(), ['movement_type' => 'new_position', 'headcount' => 1, 'designation_id' => $manager->id, 'effective_date' => '2027-03-01'], $this->creator);
    app(WorkforcePlans::class)->submit($wp->versions()->first(), $this->creator);
    app(WorkforcePlans::class)->startReview($wp->versions()->first()->refresh(), $this->approver);
    app(WorkforcePlans::class)->approve($wp->versions()->first()->refresh(), null, $this->approver);
    app(WorkforcePlans::class)->publish($wp->versions()->first()->refresh(), null, $this->approver);
    app(WorkforcePlans::class)->proposePositionFromLine($line->refresh(), 'PM-2', $this->creator);

    expect($fingerprint())->toBe($before);
});

it('reminds plan owners and position owners, throttled, and runs as a tenant-bound job; webhooks carry capacity events only', function () {
    Http::fake();
    WebhookEndpoint::create(['name' => 'All', 'url' => 'https://all.example.test/hooks', 'secret' => 's', 'events' => ['*']]);
    $position = openPosition(['code' => 'V-1', 'title' => 'Vacant analyst', 'organisation_node_id' => $this->org['department']->id], $this->creator, $this->approver, '2026-08-01');
    $employee = activeEmployee();
    app(AssignPositionAction::class)->handle($employee, ['position_id' => $position->id], 'transfer', '2026-08-15', 'Seat');
    app(AssignPositionAction::class)->handle($employee, ['vacate_position' => true], 'reassignment', '2026-09-01', 'Moved');

    $plans = app(WorkforcePlans::class);
    $plan = $plans->create(['code' => 'Q4', 'name' => 'Q4', 'company_id' => $this->org['company']->id, 'period_type' => 'quarterly', 'period_start' => '2026-10-01', 'period_end' => '2026-12-31'], $this->creator);
    $plans->addLine($plan->versions()->first(), ['movement_type' => 'expansion', 'headcount' => 1, 'effective_date' => '2026-11-01'], $this->creator);
    $plans->submit($plan->versions()->first(), $this->creator);

    $this->travelTo('2026-10-12 09:00:00');
    $first = app(WorkforceReminders::class)->tick();
    $again = app(WorkforceReminders::class)->tick();
    expect($first)->toBe(['pending_approval' => 1, 'plan_expiry' => 0, 'vacancies' => 1])
        ->and($again)->toBe(['pending_approval' => 0, 'plan_expiry' => 0, 'vacancies' => 0])
        ->and(NotificationDelivery::query()->where('user_id', $this->creator->id)->whereIn('event', ['workforce.reminder.pending_approval', 'workforce.reminder.vacancy'])->count())->toBe(2)
        ->and(NotificationDelivery::query()->where('user_id', $employee->user->id)->count())->toBe(0)
        ->and(WorkforceReminderLog::query()->count())->toBe(2);

    $events = WebhookDelivery::query()->pluck('event')->unique()->values()->all();
    expect($events)->toContain('workforce.position.opened', 'workforce.position.vacated')
        ->and($events)->not->toContain('workforce.position.occupied', 'workforce.plan.submitted', 'workforce.reminder.vacancy');

    Queue::fake();
    $this->artisan('peopleos:workforce:send-reminders', ['--queue' => true])->assertSuccessful();
    Queue::assertPushed(SendWorkforceReminders::class, fn ($job) => $job->tenantId() === $this->tenant->id);
});
