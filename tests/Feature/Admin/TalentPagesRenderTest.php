<?php

use App\Domain\Career\Models\CareerGoal;
use App\Domain\Career\Models\CareerProfile;
use App\Domain\Career\Models\CareerTrack;
use App\Domain\Career\Services\CareerArchitecture;
use App\Domain\Career\Services\CareerProfiles;
use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Organisation\Models\Designation;
use App\Domain\People\Models\Skill;
use App\Domain\Succession\Models\CriticalPosition;
use App\Domain\Succession\Models\ReadinessAssessment;
use App\Domain\Succession\Models\SuccessionPlan;
use App\Domain\Succession\Models\Successor;
use App\Domain\Succession\Services\CriticalPositions;
use App\Domain\Succession\Services\Readiness;
use App\Domain\Succession\Services\SuccessionPlans;
use App\Domain\Talent\Models\TalentPool;
use App\Domain\Talent\Models\TalentPoolMembership;
use App\Domain\Talent\Models\TalentReviewSession;
use App\Domain\Talent\Services\TalentPools;
use App\Domain\Talent\Services\TalentReviews;
use App\Filament\Pages\MyCareer;
use App\Filament\Pages\TalentAnalyticsPage;
use App\Filament\Pages\TalentDashboard;
use App\Filament\Pages\TeamCareer;
use App\Filament\Resources\CareerPaths\CareerPathResource;
use App\Filament\Resources\CareerProfiles\CareerProfileResource;
use App\Filament\Resources\CareerTracks\CareerTrackResource;
use App\Filament\Resources\CriticalPositions\CriticalPositionResource;
use App\Filament\Resources\CriticalPositions\Pages\ManageCriticalPositions;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\ReadinessAssessments\ReadinessAssessmentResource;
use App\Filament\Resources\RoleRequirements\Pages\ManageRoleRequirements;
use App\Filament\Resources\RoleRequirements\RoleRequirementResource;
use App\Filament\Resources\SuccessionPlans\Pages\ManageSuccessionPlans;
use App\Filament\Resources\SuccessionPlans\SuccessionPlanResource;
use App\Filament\Resources\Successors\Pages\ManageSuccessors;
use App\Filament\Resources\Successors\SuccessorResource;
use App\Filament\Resources\TalentDevelopmentActions\TalentDevelopmentActionResource;
use App\Filament\Resources\TalentPools\Pages\ManageTalentPools;
use App\Filament\Resources\TalentPools\TalentPoolResource;
use App\Filament\Resources\TalentReviews\Pages\ManageTalentReviews;
use App\Filament\Resources\TalentReviews\TalentReviewResource;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->manager = activeEmployee(null, ['career.self', 'career.team', 'succession.team', 'talent.review']);
    $this->employee = activeEmployee($this->manager, ['career.self']);
    $head = Designation::query()->create(['name' => 'Regional Head', 'code' => 'RHEAD']);
    $incumbent = activeEmployee(null, ['career.self']);
    app(AssignPositionAction::class)->handle($incumbent, ['designation_id' => $head->id], 'promotion', '2026-04-01', 'Appointed');

    CareerTrack::create(['code' => 'LEAD', 'name' => 'Leadership track', 'track_type' => 'leadership']);
    $skill = Skill::create(['name' => 'Stakeholder management', 'code' => 'STAKE']);
    app(CareerArchitecture::class)->publishRequirements($head, null, ['skills' => [['skill_id' => $skill->id, 'level' => 3]], 'min_experience_years' => 5], '2026-01-01', $this->admin);
    $profiles = app(CareerProfiles::class);
    $profiles->updateProfile($this->employee, ['target_designation_ids' => [$head->id], 'share_aspirations_with_manager' => true], null, $this->employee->user);
    $profiles->recordAspiration($this->employee, 'long', ['target_designation_id' => $head->id, 'aspiration' => 'Lead the region'], $this->employee->user);
    $profiles->createGoal($this->employee, 'Run a regional project', 'assignment', [], $this->employee->user);

    $position = app(CriticalPositions::class)->designate($head, null, 'Regional Head North', ['criticality' => 'high', 'business_impact' => 'high', 'scarcity' => 'medium', 'replacement_difficulty' => 'high', 'operational_dependency' => 'medium', 'reason' => 'Revenue owner'], 12, $this->admin);
    $plan = app(SuccessionPlans::class)->create($position, ['vacancy_risk' => 'medium'], $this->admin);
    $successor = app(SuccessionPlans::class)->addSuccessor($plan, $this->employee, 'Builds teams', null, null, $this->admin);
    app(Readiness::class)->assess($this->employee, $position->id, null, '1_2_years', 'Needs P&L exposure', null, $this->admin, $successor);
    app(SuccessionPlans::class)->addDevelopmentAction($successor, 'rotation', 'Six-month regional rotation', [], $this->admin);
    $pool = TalentPool::create(['code' => 'EMERGING', 'name' => 'Emerging leaders']);
    app(TalentPools::class)->add($pool, $this->employee, 'Consistent delivery', $this->admin);
    $review = app(TalentReviews::class)->create('North talent review', null, [$this->manager->user->id], $this->admin, '2026-10-20');
    app(TalentReviews::class)->addEmployees($review, [$this->employee->id], $this->admin);
    actAsTenant(null);
});

it('renders the talent administration screens', function () {
    $this->get(TalentDashboard::getUrl())->assertOk()->assertSee('Critical positions')->assertSee('Regional Head North');
    $this->get(TalentAnalyticsPage::getUrl())->assertOk()->assertSee('Bench strength')->assertSee('Regional Head North');
    $this->get(CareerTrackResource::getUrl('index'))->assertOk()->assertSee('Leadership track');
    $this->get(CareerPathResource::getUrl('index'))->assertOk();
    $this->get(RoleRequirementResource::getUrl('index'))->assertOk()->assertSee('Regional Head');
    $this->get(CareerProfileResource::getUrl('index'))->assertOk()->assertSee($this->employee->employee_code);
    $this->get(CriticalPositionResource::getUrl('index'))->assertOk()->assertSee('Regional Head North');
    $this->get(SuccessionPlanResource::getUrl('index'))->assertOk()->assertSee('Regional Head North');
    $this->get(SuccessorResource::getUrl('index'))->assertOk()->assertSee($this->employee->employee_code);
    $this->get(ReadinessAssessmentResource::getUrl('index'))->assertOk()->assertSee('Ready 1–2 years', false);
    $this->get(TalentDevelopmentActionResource::getUrl('index'))->assertOk()->assertSee('Six-month regional rotation');
    $this->get(TalentPoolResource::getUrl('index'))->assertOk()->assertSee('Emerging leaders');
    $this->get(TalentReviewResource::getUrl('index'))->assertOk()->assertSee('North talent review');
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertOk();
});

it('gives employees My career without candidacy and managers Team career with only what is shared', function () {
    $this->actingAs($this->employee->user);
    $this->get(MyCareer::getUrl())->assertOk()->assertSee('Lead the region')->assertSee('Run a regional project')->assertSee('Stakeholder management')
        ->assertDontSee('Regional Head North')->assertDontSee('My succession candidacy');
    foreach ([TalentDashboard::getUrl(), TeamCareer::getUrl(), SuccessorResource::getUrl('index'), TalentPoolResource::getUrl('index'), CriticalPositionResource::getUrl('index'), TalentAnalyticsPage::getUrl()] as $url) {
        expect($this->get($url)->status())->toBe(403, $url);
    }

    $this->actingAs($this->manager->user);
    $this->get(TeamCareer::getUrl())->assertOk()->assertSee($this->employee->employee_code)->assertSee('Regional Head')->assertSee('Run a regional project')
        ->assertSee('Regional Head North')->assertSee('North talent review')->assertSee('Not shared');
    $this->get(TalentPoolResource::getUrl('index'))->assertForbidden();
    $this->get(TalentDashboard::getUrl())->assertForbidden();
});

it('runs the main talent and succession actions from the screens through the domain services', function () {
    actAsTenant($this->tenant);
    $other = activeEmployee(null, ['career.self']);
    $role = Designation::query()->create(['name' => 'Finance Head', 'code' => 'FHEAD']);

    Livewire::test(ManageCriticalPositions::class)
        ->callAction('designate', data: ['designation_id' => $role->id, 'title' => 'Finance Head', 'review_frequency_months' => 6, 'criticality' => 'critical', 'business_impact' => 'high', 'scarcity' => 'high', 'replacement_difficulty' => 'high', 'operational_dependency' => 'high', 'reason' => 'Statutory sign-off'])
        ->assertHasNoActionErrors()->assertNotified('Critical position designated');
    $position = CriticalPosition::query()->where('title', 'Finance Head')->firstOrFail();
    Livewire::test(ManageCriticalPositions::class)->mountTableAction('facts', $position)->assertHasNoTableActionErrors();
    Livewire::test(ManageCriticalPositions::class)->callTableAction('plan', $position, data: ['vacancy_risk' => 'high'])->assertNotified('Succession plan created');
    $plan = SuccessionPlan::query()->where('critical_position_id', $position->id)->firstOrFail();

    Livewire::test(ManageSuccessionPlans::class)->callTableAction('addSuccessor', $plan, data: ['employee_id' => $other->id, 'strengths' => 'Audit lead'])->assertNotified('Successor added');
    Livewire::test(ManageSuccessionPlans::class)->mountTableAction('successors', $plan)->assertHasNoTableActionErrors();
    Livewire::test(ManageSuccessionPlans::class)->callTableAction('transition', $plan, data: ['to' => 'active'])->assertNotified('Status changed');
    $successor = Successor::query()->where('employee_id', $other->id)->firstOrFail();
    Livewire::test(ManageSuccessors::class)->callTableAction('readiness', $successor, data: ['level' => 'ready_now', 'reason' => 'Ran the audit'])->assertNotified('Readiness recorded');
    Livewire::test(ManageSuccessors::class)->callTableAction('develop', $successor, data: ['type' => 'coaching', 'title' => 'Executive coaching'])->assertNotified("Development action added to the employee's plan");
    expect(ReadinessAssessment::query()->where('employee_id', $other->id)->value('readiness_level'))->toBe('ready_now');

    $pool = TalentPool::query()->firstOrFail();
    Livewire::test(ManageTalentPools::class)->callTableAction('add', $pool, data: ['employee_ids' => [$other->id], 'reason' => 'Audit leadership'])->assertNotified('1 added');
    Livewire::test(ManageTalentPools::class)->mountTableAction('members', $pool)->assertHasNoTableActionErrors();
    expect(TalentPoolMembership::query()->where('employee_id', $other->id)->where('status', 'active')->exists())->toBeTrue();

    $review = TalentReviewSession::query()->firstOrFail();
    Livewire::test(ManageTalentReviews::class)->callTableAction('start', $review)->assertNotified('Review started');
    $item = $review->items()->firstOrFail();
    Livewire::test(ManageTalentReviews::class)->callTableAction('decide', $review, data: ['item_id' => $item->id, 'decision' => 'accelerate_development', 'reason' => 'Ready for stretch'])->assertNotified('Decision recorded');
    Livewire::test(ManageTalentReviews::class)->callTableAction('complete', $review, data: ['summary' => 'One decision'])->assertNotified('Review completed');

    $skill = Skill::query()->firstOrFail();
    Livewire::test(ManageRoleRequirements::class)->callAction('publish', data: ['designation_id' => $role->id, 'effective_from' => '2026-10-01', 'skills' => [['skill_id' => $skill->id, 'level' => 2, 'required' => true]], 'min_experience_years' => 3])
        ->assertHasNoActionErrors()->assertNotified('Requirements version 1 published');

    $this->actingAs($this->employee->user);
    $profile = CareerProfile::query()->where('employee_id', $this->employee->id)->firstOrFail();
    Livewire::test(MyCareer::class)->callAction('profile', data: ['share_mobility_with_manager' => true, 'mobility' => ['relocation'], 'lock_version' => $profile->lock_version])->assertHasNoActionErrors()->assertNotified('Profile saved');
    Livewire::test(MyCareer::class)->callAction('goal', data: ['title' => 'Learn treasury', 'goal_type' => 'skill'])->assertNotified('Career goal created');
    $goal = CareerGoal::query()->where('title', 'Learn treasury')->firstOrFail();
    Livewire::test(MyCareer::class)->callAction('goalStatus', data: ['goal_id' => $goal->id, 'to' => 'paused'])->assertNotified('Goal updated');
    Livewire::test(MyCareer::class)->callAction('interest', data: ['interest_type' => 'position', 'designation_id' => $role->id])->assertNotified('Interest recorded');
    expect($profile->refresh()->share_mobility_with_manager)->toBeTrue()->and($profile->mobility)->toBe(['relocation' => true])
        ->and($goal->refresh()->status)->toBe('paused');
});
