<?php

use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\Goals;
use App\Filament\Pages\CalibrationBoard;
use App\Filament\Pages\CareerPassportPage;
use App\Filament\Resources\Appraisals\AppraisalResource;
use App\Filament\Resources\Appraisals\Pages\ViewAppraisal;
use App\Filament\Resources\CareerPaths\CareerPathResource;
use App\Filament\Resources\Competencies\CompetencyResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\FeedbackEntries\FeedbackEntryResource;
use App\Filament\Resources\FeedbackEntries\Pages\ManageFeedbackEntries;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Resources\Goals\Pages\ListGoals;
use App\Filament\Resources\ImprovementPlans\ImprovementPlanResource;
use App\Filament\Resources\Kras\KraResource;
use App\Filament\Resources\OneOnOnes\OneOnOneResource;
use App\Filament\Resources\PerformanceCycles\PerformanceCycleResource;
use App\Filament\Resources\RatingScales\RatingScaleResource;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->manager = activeEmployee(null, ['performance.team', 'performance.review', 'performance.feedback', 'performance.goals', 'task.view']);
    $this->employee = activeEmployee($this->manager);
    $this->cycle = draftCycle();
    $this->goal = app(Goals::class)->create(['employee_id' => $this->employee->id, 'performance_cycle_id' => $this->cycle->id, 'title' => 'Deliver X', 'weight' => 100]);
    $this->cycle = app(Appraisals::class)->launch($this->cycle, $this->admin);
    $this->cycle = app(Appraisals::class)->advance($this->cycle); // self review
    $this->cycle = app(Appraisals::class)->advance($this->cycle); // manager review
    $this->appraisal = Appraisal::query()->where('employee_id', $this->employee->id)->first();
    actAsTenant(null);
});

it('renders the performance pages', function () {
    $this->get(PerformanceCycleResource::getUrl('index'))->assertOk()->assertSee('FY26 annual');
    $this->get(PerformanceCycleResource::getUrl('edit', ['record' => $this->cycle]))->assertOk();
    $this->get(GoalResource::getUrl('index'))->assertOk()->assertSee('Deliver X');
    $this->get(GoalResource::getUrl('create'))->assertOk();
    $this->get(AppraisalResource::getUrl('index'))->assertOk();
    $this->get(AppraisalResource::getUrl('view', ['record' => $this->appraisal]))->assertOk()->assertSee('Manager review');
    $this->get(CalibrationBoard::getUrl())->assertOk()->assertSee('Meets Expectations');
    $this->get(CareerPassportPage::getUrl(['employee' => $this->employee->id]))->assertOk()->assertSee('Active goals');
    $this->get(FeedbackEntryResource::getUrl('index'))->assertOk();
    $this->get(OneOnOneResource::getUrl('index'))->assertOk();
    $this->get(ImprovementPlanResource::getUrl('index'))->assertOk();
    $this->get(RatingScaleResource::getUrl('index'))->assertOk()->assertSee('Five-point scale');
    $this->get(CompetencyResource::getUrl('index'))->assertOk()->assertSee('Collaboration');
    $this->get(KraResource::getUrl('index'))->assertOk();
    $this->get(CareerPathResource::getUrl('index'))->assertOk();
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertOk();
});

it('lets the manager review from the appraisal page, the employee check in on goals and give feedback, and hides admin from employees', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->manager->user);
    $competencies = collect($this->cycle->competency_ids)->mapWithKeys(fn ($id) => [$id => '4'])->all();

    Livewire::test(ViewAppraisal::class, ['record' => $this->appraisal->id])
        ->callAction('managerReview', data: ['goals' => [$this->goal->id => '5'], 'competencies' => $competencies, 'overall' => '4', 'strengths' => 'Great'])
        ->assertHasNoActionErrors()
        ->assertNotified('Review submitted');
    expect((float) $this->appraisal->refresh()->goal_score)->toBe(100.0);

    $this->actingAs($this->employee->user);
    Livewire::test(ListGoals::class)
        ->callTableAction('checkIn', $this->goal, data: ['value' => 40, 'confidence' => 'on_track', 'note' => 'Halfway'])
        ->assertNotified('Progress updated');
    expect((float) $this->goal->refresh()->progress)->toBe(40.0);

    Livewire::test(ManageFeedbackEntries::class)
        ->callAction('give', data: ['employee_id' => $this->manager->id, 'type' => 'praise', 'visibility' => 'public', 'message' => 'Thanks for the support'])
        ->assertNotified('Feedback shared');

    actAsTenant(null);
    $this->get(AppraisalResource::getUrl('index'))->assertOk()->assertSee($this->employee->person->full_name);
    $this->get(PerformanceCycleResource::getUrl('index'))->assertForbidden();
    $this->get(CalibrationBoard::getUrl())->assertForbidden();
    $this->get(CareerPassportPage::getUrl())->assertOk()->assertDontSee('Choose employee');
});
