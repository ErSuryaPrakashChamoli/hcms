<?php

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Services\ImprovementPlanRules;
use App\Domain\Performance\Services\ImprovementPlans;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Filament\Resources\ImprovementPlans\Pages\ManageImprovementPlans;
use App\Filament\Support\PolicySettingsSchema;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/* HROne setup parity HP-8f — PIP → PIP policy: the plan's period and extensions, PIP and PIP evaluation letters, start and
 * close checklists, approval of the plan and of its outcome (Approval Center or a workflow on performance.pip.requested /
 * performance.pip.evaluation_requested). Without a policy plans work exactly as before. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->pipHr = tenantUser($this->tenant, ['performance.pip', 'performance.view', 'task.view', 'task.act']);
    $this->actingAs($this->hr);
    $this->manager = activeEmployee(null, ['performance.team', 'performance.review', 'performance.goals']);
    $this->employee = activeEmployee($this->manager);
    $this->plans = app(ImprovementPlans::class);
    $this->open = fn (array $extra = []) => $this->plans->open($this->employee, $this->manager, 'Missed targets', [['objective' => 'Close 5 deals']], '2026-09-21', '2026-11-20', null, $this->manager->user, ...$extra);
});

function pippPolicy(array $settings): void
{
    $policy = Policy::create(['type' => 'pip', 'name' => 'PIP', 'code' => 'PIP_POLICY']);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'pip', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
}

function pippLetter(string $code, string $type): LetterTemplate
{
    return LetterTemplate::create(['name' => $code, 'code' => $code, 'type' => $type, 'subject' => 'Improvement plan', 'requires_approval' => false, 'body' => '<p>Dear {{ employee.first_name }}</p>']);
}

it('opens plans at once, about two months long, with any number of extensions, without a PIP policy', function () {
    $plan = ($this->open)();
    $rules = app(ImprovementPlanRules::class);

    expect($plan->status)->toBe('active')
        // Every status fits improvement_plans.status (varchar 16 on MySQL).
        ->and(max(array_map('strlen', array_keys(config('peopleos.performance.pip_statuses')))))->toBeLessThanOrEqual(16)
        ->and($rules->defaultEnd($this->employee, '2026-09-21')->toDateString())->toBe('2026-11-21')
        ->and($rules->needsApproval($this->employee))->toBeFalse()->and($rules->maxExtensions($this->employee))->toBe(0);
    foreach (['2026-12-01', '2026-12-15', '2027-01-10'] as $end) {
        $plan = $this->plans->extend($plan, $end, 'More time', $this->manager->user);
    }
    expect($plan->extension_count)->toBe(3)->and($plan->checkpoints()->count())->toBe(0)->and(Letter::query()->count())->toBe(0);
});

it('takes the period, extensions, letters and checklists from the PIP policy', function () {
    $start = pippLetter('PIP_START', 'pip');
    $outcome = pippLetter('PIP_OUTCOME', 'pip_outcome');
    pippLetter('OUR_THANKS', 'appreciation');
    pippPolicy(['duration_days' => 45, 'max_extensions' => 1, 'letter_on_activate' => 1, 'activate_letter_template' => $start->id, 'letter_on_close' => 1, 'close_letter_template' => $outcome->id,
        'map_start_checklist' => 1, 'start_checklist' => [['title' => 'Share the plan', 'due_offset_days' => 2, 'owner' => 'manager'], ['title' => 'Mid-plan review', 'due_offset_days' => 400, 'owner' => 'hr']],
        'map_close_checklist' => 1, 'close_checklist' => [['title' => 'File the evaluation', 'due_offset_days' => 5, 'owner' => 'hr']]]);

    expect(app(ImprovementPlanRules::class)->defaultEnd($this->employee, '2026-09-21')->toDateString())->toBe('2026-11-05');
    $plan = ($this->open)();
    $starts = $plan->checkpoints()->get();
    expect($starts->pluck('kind')->unique()->all())->toBe(['start'])
        ->and($starts->pluck('due_date')->map->toDateString()->all())->toBe(['2026-09-23', '2026-11-20']) // capped at the plan's end
        ->and(Letter::query()->where('source_id', $plan->id)->where('source_type', $plan->getMorphClass())->sole()->type)->toBe('pip');

    $plan = $this->plans->extend($plan, '2026-12-20', 'More time', $this->manager->user);
    expect(fn () => $this->plans->extend($plan, '2027-01-20', 'Again', $this->manager->user))->toThrow(RuntimeException::class, 'most times allowed (1)');

    $plan = $this->plans->close($plan, 'completed', 'Targets met', $this->manager->user);
    $close = $plan->checkpoints()->where('kind', 'close')->sole();
    expect($plan->status)->toBe('completed')->and($close->due_date->toDateString())->toBe('2026-09-26')
        ->and(Letter::query()->where('source_id', $plan->id)->orderBy('id')->pluck('type')->all())->toBe(['pip', 'pip_outcome'])
        ->and(fn () => $this->plans->closeOut($plan, 'Done', $this->manager->user))->toThrow(RuntimeException::class, 'close checklist');

    $plan->checkpoints()->where('kind', 'start')->get()->each(fn ($c) => expect(fn () => $this->plans->reviewCheckpoint($c, 'met', 'Done', $this->manager->user))->toThrow(RuntimeException::class, 'The plan is closed'));
    $this->plans->reviewCheckpoint($close, 'met', 'Filed', $this->manager->user);
    expect($this->plans->closeOut($plan->refresh(), 'Done', $this->manager->user)->status)->toBe('closed');

    // The letter questions offer the letter types they name.
    $field = collect(PolicySettingsSchema::flatFields('pip'))->firstWhere('key', 'activate_letter_template');
    expect((new ReflectionMethod(PolicySettingsSchema::class, 'options'))->invoke(null, $field))->toBe([$start->id => 'PIP_START'])
        ->and(config('peopleos.letters.types'))->toHaveKeys(['pip', 'pip_outcome', 'performance']);
});

it('waits for approval in the Approval Center before a plan starts, under a PIP policy that asks for it', function () {
    pippPolicy(['approval' => 'workflow']);
    $plan = ($this->open)();
    $id = 'pip:'.$plan->id;

    expect($plan->status)->toBe('pending_approval')
        ->and(fn () => ($this->open)())->toThrow(RuntimeException::class, 'already active')
        ->and(app(ApprovalCenter::class)->pending($this->pipHr)->pluck('id'))->toContain($id)
        ->and(app(ApprovalCenter::class)->pending($this->manager->user)->pluck('id'))->not->toContain($id) // the requester never decides
        ->and(app(ApprovalCenter::class)->pending($this->employee->user)->pluck('id'))->not->toContain($id)
        ->and(fn () => $this->plans->approve($plan, null, $this->manager->user))->toThrow(RuntimeException::class, 'cannot decide')
        ->and(fn () => $this->plans->close($plan, 'completed', 'x', $this->manager->user))->toThrow(RuntimeException::class, 'already closed');

    app(ApprovalDecisions::class)->decide($this->pipHr, $id, 'approve', 'Agreed');
    expect($plan->refresh()->status)->toBe('active')->and($plan->approved_by)->toBe($this->pipHr->id)
        ->and($this->employee->user->notifications()->get()->pluck('data.title')->filter(fn ($t) => str_contains((string) $t, 'Improvement plan opened'))->count())->toBe(1);

    $this->plans->close($plan, 'cancelled', 'Withdrawn', $this->manager->user);
    $second = ($this->open)();
    app(ApprovalDecisions::class)->decide($this->pipHr, 'pip:'.$second->id, 'reject', 'Coach first');
    expect($second->refresh()->status)->toBe('cancelled')->and($second->decision_note)->toBe('Coach first');

    // A draft asks for approval when it is activated.
    $draft = ($this->open)([true]);
    expect($draft->status)->toBe('draft')->and($this->plans->activate($draft, $this->manager->user)->status)->toBe('pending_approval');
});

it('approves a plan and its outcome through workflows on performance.pip.requested and performance.pip.evaluation_requested', function () {
    pippPolicy(['approval' => 'workflow', 'evaluation_approval' => 1]);
    [$nodes, $edges] = linear([['id' => 'a', 'type' => 'approval', 'name' => 'HR', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $this->pipHr->id]]]]);
    publishWorkflow($nodes, $edges, ['name' => 'PIP request', 'key' => 'pip_request', 'trigger_event' => 'performance.pip.requested']);
    publishWorkflow($nodes, $edges, ['name' => 'PIP evaluation', 'key' => 'pip_evaluation', 'trigger_event' => 'performance.pip.evaluation_requested']);
    $task = fn (ImprovementPlan $plan) => WorkflowTask::query()->whereIn('workflow_instance_id', WorkflowInstance::query()->where('subject_type', $plan->getMorphClass())->where('subject_id', $plan->id)->pluck('id'))->where('status', 'pending')->sole();

    $plan = ($this->open)();
    expect(app(ApprovalCenter::class)->pending($this->pipHr)->pluck('id'))->not->toContain('pip:'.$plan->id);
    app(WorkflowEngine::class)->completeTask($task($plan), 'approved', 'Go ahead', $this->pipHr);
    expect($plan->refresh()->status)->toBe('active');

    $plan = $this->plans->close($plan, 'unsuccessful', 'Targets missed', $this->manager->user);
    expect($plan->status)->toBe('pending_outcome')->and($plan->requested_outcome)->toBe('unsuccessful')->and($plan->closed_at)->toBeNull();
    app(WorkflowEngine::class)->completeTask($task($plan), 'rejected', 'Give two more weeks', $this->pipHr);
    expect($plan->refresh()->status)->toBe('active')->and($plan->requested_outcome)->toBeNull();

    $plan = $this->plans->close($plan, 'completed', 'Met in the end', $this->manager->user);
    app(WorkflowEngine::class)->completeTask($task($plan), 'approved', 'Agreed', $this->pipHr);
    expect($plan->refresh()->status)->toBe('completed')->and($plan->closed_at)->not->toBeNull()->and($plan->outcome)->toBe('Met in the end');
});

it('decides a PIP evaluation in the Approval Center without a workflow, and checks the policy\'s answers', function () {
    pippPolicy(['evaluation_approval' => 1]);
    $plan = $this->plans->close(($this->open)(), 'completed', 'Targets met', $this->manager->user);
    $id = 'pip_evaluation:'.$plan->id;

    expect(app(ApprovalCenter::class)->pending($this->pipHr)->firstWhere('id', $id)?->label('approve'))->toBe('Approve outcome');
    app(ApprovalDecisions::class)->decide($this->pipHr, $id, 'reject', 'Evidence missing');
    expect($plan->refresh()->status)->toBe('active');
    $plan = $this->plans->close($plan, 'completed', 'Evidence attached', $this->manager->user);
    app(ApprovalDecisions::class)->decide($this->pipHr, $id, 'approve');
    expect($plan->refresh()->status)->toBe('completed');

    $bad = Policy::create(['type' => 'pip', 'name' => 'Bad', 'code' => 'BAD_PIP']);
    app(Policies::class)->draft($bad, ['letter_on_activate' => 1]);
    expect(fn () => app(Policies::class)->publish($bad))->toThrow(ConfigurationException::class, 'Choose the letter template');
    app(Policies::class)->draft($bad, ['map_close_checklist' => 1, 'close_checklist' => []]);
    expect(fn () => app(Policies::class)->publish($bad))->toThrow(ConfigurationException::class, 'at least one checklist item');
});

it('opens a plan from the screen with the policy\'s end date, and says when it waits for approval', function () {
    pippPolicy(['duration_days' => 30, 'approval' => 'workflow']);
    $this->actingAs($this->manager->user);
    Livewire::test(ManageImprovementPlans::class)->mountAction('create')
        ->fillForm(['employee_id' => $this->employee->id, 'start_date' => '2026-10-01'])->assertActionDataSet(['end_date' => '2026-10-31'])
        ->fillForm(['reason' => 'Quality issues', 'objectives' => [['objective' => 'Zero escalations', 'measure' => 'Tickets']]])
        ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Plan sent for approval');
    expect(ImprovementPlan::query()->sole()->only(['status', 'end_date']))->toMatchArray(['status' => 'pending_approval']);
});
