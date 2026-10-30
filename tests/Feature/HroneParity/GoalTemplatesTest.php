<?php

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Feedback\Models\FeedbackRequest;
use App\Domain\Feedback\Services\FeedbackTemplates;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalTemplate;
use App\Domain\Performance\Models\Kra;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\GoalTemplates;
use App\Filament\Resources\Goals\Pages\ListGoals;
use App\Filament\Resources\GoalTemplates\GoalTemplateResource;
use App\Filament\Resources\GoalTemplates\Pages\CreateGoalTemplate;
use App\Filament\Resources\GoalTemplates\Pages\EditGoalTemplate;
use App\Filament\Support\PolicySettingsSchema;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/* HROne setup parity HP-8e — Goal → Goal template: KRAs with their KPIs, "Add from library", the system mapping of the
 * goal sheet policy (at goal setting or launch), "Use template" on the goals screen, applicability and the feedback form
 * the manager answers at the manager review. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->manager = activeEmployee(null, ['performance.team', 'performance.review', 'performance.goals', 'task.view', 'task.act', 'feedback.view']);
    $this->employee = activeEmployee($this->manager);
    $this->templates = app(GoalTemplates::class);
    $this->template = GoalTemplate::create(['code' => 'sales', 'name' => 'Sales goal sheet']);
    $kra = $this->template->items()->create(['title' => 'Revenue', 'weight' => 60, 'target_value' => 100]);
    $kra->kpis()->create(['title' => 'New logos', 'weight' => 50, 'target_value' => 10, 'measure_type' => 'numeric']);
    $kra->kpis()->create(['title' => 'Renewals', 'weight' => 50, 'target_value' => 90]);
    $this->template->items()->create(['title' => 'Pipeline hygiene', 'weight' => 40]);
});

function gtpPolicy(array $settings): void
{
    $policy = Policy::create(['type' => 'goal_sheet', 'name' => 'Mapped goals', 'code' => 'MAPPED']);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'goal_sheet', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
}

it('assigns a template\'s KRAs and KPIs as draft goals once, and builds templates from the KRA library', function () {
    $cycle = draftCycle();
    $goals = $this->templates->assign($this->template, $this->employee, $cycle);
    $revenue = Goal::query()->where('title', 'Revenue')->sole();

    expect($goals)->toHaveCount(2)
        ->and($revenue->only(['status', 'source', 'goal_template_id', 'performance_cycle_id', 'weight']))->toBe(['status' => 'draft', 'source' => 'system', 'goal_template_id' => $this->template->id, 'performance_cycle_id' => $cycle->id, 'weight' => 60])
        ->and($revenue->keyResults()->pluck('title')->all())->toBe(['New logos', 'Renewals'])
        ->and((float) $revenue->keyResults()->first()->target_value)->toBe(10.0)
        ->and($this->templates->assign($this->template, $this->employee, $cycle))->toHaveCount(0) // KRAs already on the sheet are skipped
        ->and(fn () => $this->template->delete())->toThrow(RuntimeException::class, 'make it inactive');

    $library = Kra::create(['code' => 'CSAT', 'name' => 'Customer satisfaction', 'default_weight' => 20, 'kpis' => [['name' => 'NPS', 'target' => 60], ['name' => 'Tickets closed', 'target' => 300]]]);
    $item = $this->templates->addFromLibrary($this->template, $library, $this->hr);
    expect($item->only(['title', 'weight', 'kra_id']))->toBe(['title' => 'Customer satisfaction', 'weight' => 20, 'kra_id' => $library->id])
        ->and($item->kpis()->pluck('title')->all())->toBe(['NPS', 'Tickets closed'])
        ->and(fn () => $this->templates->addFromLibrary($this->template, $library, $this->employee->user))->toThrow(RuntimeException::class, 'cannot change goal templates');

    $this->template->update(['status' => 'inactive']);
    expect(fn () => $this->templates->assign($this->template->refresh(), $this->employee, $cycle))->toThrow(RuntimeException::class, 'inactive');
});

it('maps goal templates when a cycle reaches goal setting only for employees whose goal sheet policy says so', function () {
    $without = app(Appraisals::class)->launch(draftCycle(['code' => 'NOPOLICY']));
    expect(Goal::query()->where('performance_cycle_id', $without->id)->count())->toBe(0);

    gtpPolicy(['goal_creation' => 'system', 'goal_template_code' => 'SALES', 'goal_sheet_owner' => 'manager']);
    $cycle = app(Appraisals::class)->launch(draftCycle(['code' => 'MAPPED']));
    $mapped = Goal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $this->employee->id)->get();
    expect($mapped->pluck('title')->sort()->values()->all())->toBe(['Pipeline hygiene', 'Revenue'])
        ->and($mapped->pluck('goal_template_id')->unique()->all())->toBe([$this->template->id])
        ->and($this->manager->user->notifications()->get()->pluck('data.title')->filter(fn ($t) => str_contains((string) $t, 'Goal sheet ready'))->count())->toBeGreaterThanOrEqual(1);

    // Running the mapping again adds nothing.
    expect($this->templates->assignForCycle($cycle))->toBe(0);
});

it('picks the first template whose "Map template" conditions match when the policy names none', function () {
    gtpPolicy(['goal_creation' => 'system']);
    $this->template->update(['applicability' => [['field' => 'employee_id', 'operator' => 'in', 'value' => [-1]]]]);
    $other = GoalTemplate::create(['code' => 'general', 'name' => 'General goal sheet']);
    $other->items()->create(['title' => 'Learning', 'weight' => 100]);

    expect($this->templates->templateFor($this->employee)?->id)->toBe($other->id);
    $cycle = app(Appraisals::class)->launch(draftCycle(['stages' => [['key' => 'manager_review', 'name' => 'Manager review', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31']]]));
    expect(Goal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $this->employee->id)->pluck('title')->all())->toBe(['Learning']);
});

it('lets employees use a template for their own goals from the goals screen, not for others', function () {
    $colleague = activeEmployee();
    $this->actingAs($this->employee->user);
    Livewire::test(ListGoals::class)->assertOk()->assertActionVisible('useGoalTemplate')
        ->callAction('useGoalTemplate', ['goal_template_id' => $this->template->id, 'employee_id' => $this->employee->id, 'performance_cycle_id' => null])->assertHasNoActionErrors();
    expect(Goal::query()->where('employee_id', $this->employee->id)->where('goal_template_id', $this->template->id)->count())->toBe(2)
        ->and(Goal::query()->where('title', 'Revenue')->value('source'))->toBe('manual')
        ->and(fn () => $this->templates->assign($this->template, $colleague, null, $this->employee->user))->toThrow(RuntimeException::class, 'only set goals for yourself');
});

it('asks the manager for the template\'s feedback form with the manager review', function () {
    $form = app(FeedbackTemplates::class)->saveTemplate(null, ['code' => 'gs-fb', 'name' => 'Goal sheet feedback', 'process' => 'goal_review', 'questions' => [['title' => 'Were the goals stretching?', 'type' => 'text']]], 'Set up', $this->hr);
    $this->template->update(['feedback_template_id' => $form->id]);
    expect($form->module)->toBe('performance')->and(FeedbackTemplates::options('goal_review'))->toBe([$form->id => 'Goal sheet feedback']);

    $cycle = app(Appraisals::class)->launch(draftCycle());
    $this->templates->assign($this->template, $this->employee, $cycle);
    $cycle = app(Appraisals::class)->advance($cycle); // self review
    expect(FeedbackRequest::query()->count())->toBe(0);
    app(Appraisals::class)->advance($cycle); // manager review

    $request = FeedbackRequest::query()->sole();
    $review = Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $this->employee->id)->sole()->review('manager');
    expect($request->only(['process', 'respondent', 'respondent_user_id', 'employee_id']))->toBe(['process' => 'goal_review', 'respondent' => 'manager', 'respondent_user_id' => $this->manager->user->id, 'employee_id' => $this->employee->id])
        ->and($request->subject_id)->toBe($review->id);
});

it('manages goal templates on their own screens for performance administrators only', function () {
    $this->get(GoalTemplateResource::getUrl('index'))->assertOk()->assertSee('Sales goal sheet');
    $this->get(GoalTemplateResource::getUrl('edit', ['record' => $this->template]))->assertOk();

    $undo = Repeater::fake();
    Livewire::test(CreateGoalTemplate::class)->fillForm(['name' => 'Support goal sheet', 'code' => 'support', 'status' => 'active', 'goals' => [
        ['title' => 'Resolution time', 'weight' => 70, 'measure_type' => 'numeric', 'start_value' => 0, 'kpis' => [['title' => 'P1 under 4h', 'weight' => 100, 'measure_type' => 'percentage', 'start_value' => 0]]],
    ]])->call('create')->assertHasNoFormErrors();
    $undo();
    $support = GoalTemplate::query()->where('code', 'SUPPORT')->sole();
    $kpi = $support->items()->whereNotNull('parent_item_id')->sole();
    expect($support->goals()->pluck('title')->all())->toBe(['Resolution time'])
        ->and($kpi->only(['title', 'goal_template_id']))->toBe(['title' => 'P1 under 4h', 'goal_template_id' => $support->id]);
    Livewire::test(EditGoalTemplate::class, ['record' => $support->id])->callAction('addFromLibrary', ['kra_id' => Kra::create(['code' => 'FCR', 'name' => 'First contact resolution', 'kpis' => []])->id]);
    expect($support->goals()->pluck('title')->all())->toBe(['Resolution time', 'First contact resolution']);

    $policyOptions = (new ReflectionMethod(PolicySettingsSchema::class, 'options'))->invoke(null, ['options_from' => 'goal_templates']);
    expect($policyOptions)->toBe(['SALES' => 'Sales goal sheet', 'SUPPORT' => 'Support goal sheet'])
        ->and(app(SetupCatalogue::class)->optionsFor(['type' => 'select', 'options_from' => 'goal_templates']))->toBe($policyOptions);

    $this->actingAs($this->employee->user);
    $this->get(GoalTemplateResource::getUrl('index'))->assertForbidden();
});
