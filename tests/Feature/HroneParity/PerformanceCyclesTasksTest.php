<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Experience\Services\ExperienceTasks;
use App\Domain\Experience\Services\UnifiedInbox;
use App\Domain\Experience\Services\WorkInbox;
use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Organisation\Models\Company;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Models\FeedbackNomination;
use App\Domain\Performance\Models\FeedbackParticipant;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalPlan;
use App\Domain\Performance\Models\GoalTemplate;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformanceReminderLog;
use App\Domain\Performance\Models\PerformanceWindowExtension;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\FeedbackNominations;
use App\Domain\Performance\Services\FeedbackRounds;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\ImprovementPlans;
use App\Domain\Performance\Services\PerformanceDistribution;
use App\Domain\Performance\Services\PerformancePolicyRules;
use App\Domain\Performance\Services\PerformanceReminders;
use App\Domain\Performance\Services\PerformanceWindows;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\AppraisalReviews\AppraisalReviewResource;
use App\Filament\Resources\AppraisalReviews\Pages\ListAppraisalReviews;
use App\Filament\Resources\Appraisals\Pages\ListAppraisals;
use App\Filament\Resources\Appraisals\Pages\ViewAppraisal;
use App\Filament\Resources\FeedbackRounds\Pages\ViewFeedbackRound;
use App\Filament\Resources\GoalPlans\Pages\ListGoalPlans;
use App\Filament\Resources\GoalSheets\GoalSheetResource;
use App\Filament\Resources\PerformanceCycles\Pages\EditPerformanceCycle;
use App\Filament\Resources\PerformanceCycles\RelationManagers\WindowExtensionsRelationManager;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/*
| HROne parity GO-2 (cycle population, windows and withdrawal: "Add employees to cycle", window extensions per stage for
| everyone or chosen people, "Withdraw from cycle" — nothing is deleted) and GO-9 (performance task kinds in My tasks /
| the unified inbox: reviews to write, goal sheets to create, goal check-ins, ratings to acknowledge, PIP evaluations
| due). Defaults keep today's behaviour: without an extension every window is the stage's own; nothing is added or
| withdrawn unless HR does it.
*/

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00'); // a Monday
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->manager = activeEmployee(null, ['performance.team', 'performance.review', 'performance.goals', 'task.view', 'task.act']);
    $this->employee = activeEmployee($this->manager, ['performance.goals', 'performance.review', 'performance.feedback', 'task.view']);
    $this->peer = activeEmployee($this->manager, ['performance.goals', 'performance.review', 'performance.feedback', 'task.view']);
    $this->appraisals = app(Appraisals::class);
    $this->windows = app(PerformanceWindows::class);
    $this->settings = app(SettingsRepository::class);
});

/** Stage windows around "now" (21 Sep 2026): goal setting until 25 Sep, self review until 5 Oct, manager review until 15 Oct. */
function go29Stages(): array
{
    return [
        ['key' => 'goal_setting', 'name' => 'Goal setting', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-25'],
        ['key' => 'self_review', 'name' => 'Self review', 'starts_on' => '2026-09-26', 'ends_on' => '2026-10-05'],
        ['key' => 'manager_review', 'name' => 'Manager review', 'starts_on' => '2026-10-06', 'ends_on' => '2026-10-15'],
        ['key' => 'calibration', 'name' => 'Calibration', 'starts_on' => '2026-10-16', 'ends_on' => '2026-10-20'],
        ['key' => 'final', 'name' => 'Final rating', 'starts_on' => '2026-10-21', 'ends_on' => '2026-10-31'],
    ];
}

/** A launched cycle with the stages above, moved to $stage. */
function go29Cycle(string $stage = 'goal_setting', array $overrides = []): PerformanceCycle
{
    $appraisals = app(Appraisals::class);
    $cycle = $appraisals->launch(draftCycle($overrides + ['stages' => go29Stages()]));
    while ($cycle->current_stage !== $stage) {
        $cycle = $appraisals->advance($cycle);
    }

    return $cycle->refresh();
}

function go29Appraisal(PerformanceCycle $cycle, Employee $employee): Appraisal
{
    return Appraisal::query()->withoutGlobalScope(AccessScope::class)->where('performance_cycle_id', $cycle->id)->where('employee_id', $employee->id)->firstOrFail();
}

function go29Policy(string $type, array $settings, string $code): Policy
{
    PolicyAssignmentRule::query()->where('policy_type', $type)->delete();
    $policy = Policy::create(['type' => $type, 'name' => $code, 'code' => $code]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
    app(PerformancePolicyRules::class)->forget();

    return $policy;
}

/** @return list<string> the kinds of the person's performance tasks */
function go29Tasks(Employee $employee, ?string $kind = null)
{
    return app(ExperienceTasks::class)->for($employee->user->fresh(), $employee)
        ->filter(fn ($t) => $t->domain === 'performance' && ($kind === null || $t->kind === $kind))->values();
}

function go29Titles($user, string $needle)
{
    return $user->notifications()->get()->pluck('data.title')->filter(fn ($t) => str_contains((string) $t, $needle))->values();
}

// ---- Defaults ----------------------------------------------------------------------------------------------------

it('keeps today\'s behaviour by default: the stage\'s own window, everyone launched once, nothing withdrawn', function () {
    $cycle = go29Cycle('self_review');
    $review = go29Appraisal($cycle, $this->employee)->review('self');

    expect($this->windows->stageEnd($cycle, 'self_review', $this->employee->id)->toDateString())->toBe('2026-10-05')
        ->and($this->windows->stageEnds($cycle, 'goal_setting', [$this->employee->id, $this->peer->id]))->toEqual([$this->employee->id => now()->setDate(2026, 9, 25)->startOfDay(), $this->peer->id => now()->setDate(2026, 9, 25)->startOfDay()])
        ->and(AppraisalReviewResource::dueOn($review))->toBe('2026-10-05')
        ->and(PerformanceWindowExtension::query()->count())->toBe(0)
        ->and(Appraisal::query()->where('performance_cycle_id', $cycle->id)->pluck('status')->unique()->all())->toBe(['in_review'])
        ->and(Appraisal::query()->where('performance_cycle_id', $cycle->id)->count())->toBe(3)
        ->and(Schema::hasTable('performance_window_extensions'))->toBeTrue()
        ->and(collect(DataInventory::TABLES)->flatten()->contains('performance_window_extensions'))->toBeTrue()
        ->and(config('peopleos.performance.appraisal_statuses'))->toHaveKey('withdrawn');
});

// ---- Add employees to cycle --------------------------------------------------------------------------------------

it('adds a joiner to a running cycle through the launch path, once, with the reviews of the open stages', function () {
    $cycle = go29Cycle('self_review');
    $joiner = activeEmployee($this->manager);
    Event::fake([PerformanceEvent::class]);

    $result = $this->appraisals->addEmployee($cycle, $joiner, $this->hr);
    $appraisal = $result['appraisal'];
    expect($result['created'])->toBeTrue()
        ->and($result['reviews'])->toBe(2)
        ->and($appraisal->status)->toBe('in_review')
        ->and((int) $appraisal->manager_id)->toBe($this->manager->id)
        ->and((int) $appraisal->performance_template_version_id)->toBe((int) $cycle->performance_template_version_id)
        ->and($appraisal->reviews()->pluck('status', 'type')->all())->toEqual(['self' => 'pending', 'manager' => 'pending']);
    // The self review's stage is open: the joiner is told; the manager review's stage has not opened yet.
    Event::assertDispatched(PerformanceEvent::class, fn (PerformanceEvent $e) => $e->name === 'performance.review.assigned' && $e->recipientEmployeeIds === [$joiner->id]);
    Event::assertNotDispatched(PerformanceEvent::class, fn (PerformanceEvent $e) => $e->name === 'performance.review.assigned' && $e->recipientEmployeeIds === [$this->manager->id]);
    Event::assertDispatched(PerformanceEvent::class, fn (PerformanceEvent $e) => $e->name === 'performance.appraisal.added' && $e->recipientEmployeeIds === [$joiner->id]);

    // Never a second appraisal for the same person and cycle.
    $again = $this->appraisals->addEmployee($cycle, $joiner, $this->hr);
    expect($again['created'])->toBeFalse()->and($again['reviews'])->toBe(0)
        ->and(Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $joiner->id)->count())->toBe(1)
        ->and(AuditEvent::query()->withoutTenancy()->where('entity_type', PerformanceCycle::class)->where('entity_id', (string) $cycle->id)->get()
            ->filter(fn (AuditEvent $e) => ($e->metadata['event'] ?? null) === 'employee_added' && (int) ($e->metadata['employee_id'] ?? 0) === $joiner->id))->toHaveCount(1);

    // At the manager review a joiner's manager is told at once.
    $cycle = $this->appraisals->advance($cycle);
    $late = $this->appraisals->addEmployee($cycle, activeEmployee($this->manager), $this->hr)['appraisal'];
    Event::assertDispatched(PerformanceEvent::class, fn (PerformanceEvent $e) => $e->name === 'performance.review.assigned' && $e->recipientEmployeeIds === [$this->manager->id]
        && $e->subject instanceof AppraisalReview && (int) $e->subject->appraisal_id === $late->id && $e->subject->type === 'manager');
});

it('refuses to add people outside the launch rules, the running cycle, the rights or the access scope', function () {
    $draft = draftCycle(['code' => 'DRAFT', 'stages' => go29Stages()]);
    $cycle = go29Cycle('goal_setting');
    $joiner = activeEmployee($this->manager);

    expect(fn () => $this->appraisals->addEmployee($draft, $joiner, $this->hr))->toThrow(RuntimeException::class, 'running cycle')
        ->and(fn () => $this->appraisals->addEmployee($cycle, $joiner, $this->manager->user))->toThrow(RuntimeException::class, 'cannot add employees');

    // An exited employee, and (HP-8i) a joiner past the performance policy's cut-off.
    $gone = activeEmployee($this->manager);
    forceLifecycle($gone, LifecycleState::Exited);
    expect(fn () => $this->appraisals->addEmployee($cycle, $gone->refresh(), $this->hr))->toThrow(RuntimeException::class, 'Not employed');
    Employee::query()->whereKey($joiner->id)->update(['joining_date' => '2026-09-01']);
    go29Policy('performance', ['eligibility' => '1', 'joiner_cutoff_days' => 30], 'GO29PERF');
    expect(fn () => $this->appraisals->addEmployee($cycle, $joiner->refresh(), $this->hr))->toThrow(RuntimeException::class, 'Not eligible');

    // HR scoped to another company cannot reach the employee.
    $scoped = tenantUser($this->tenant, ['performance.view', 'performance.manage']);
    app(AccessScopes::class)->assign($scoped, ['company' => [Company::factory()->create()->id]], 'Other company only');
    expect(fn () => $this->appraisals->addEmployee($cycle, $this->peer, $scoped->fresh()))->toThrow(RuntimeException::class, 'outside your access');
});

it('adds chosen employees in bulk, one result each, and maps the goal sheet of the goal sheet policy', function () {
    $template = GoalTemplate::create(['code' => 'sales', 'name' => 'Sales goal sheet']);
    $template->items()->create(['title' => 'Revenue', 'weight' => 100, 'target_value' => 100]);
    go29Policy('goal_sheet', ['goal_creation' => 'system', 'goal_template_code' => 'SALES'], 'GO29SHEET');
    $cycle = go29Cycle('goal_setting');
    $joiner = activeEmployee($this->manager);
    $gone = activeEmployee($this->manager);
    forceLifecycle($gone, LifecycleState::Exited);

    $run = app(BulkRuns::class)->start($this->hr, 'performance_cycles', 'employees.add_to_cycle', [$joiner->id, $this->employee->id, $gone->id], ['performance_cycle_id' => $cycle->id]);
    $items = BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id');

    expect($items[$joiner->id]->outcome)->toBe('done')
        ->and($items[$joiner->id]->message)->toContain('Added to')
        ->and($items[$this->employee->id]->outcome)->toBe('done')
        ->and($items[$this->employee->id]->message)->toContain('Already in')
        ->and($items[$gone->id]->outcome)->toBe('refused')
        ->and(Goal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $joiner->id)->pluck('title')->all())->toBe(['Revenue'])
        ->and(go29Appraisal($cycle, $joiner)->status)->toBe('pending')
        ->and(fn () => app(BulkRuns::class)->start($this->hr, 'performance_cycles', 'employees.add_to_cycle', [$joiner->id], ['performance_cycle_id' => draftCycle(['code' => 'D2'])->id]))
        ->toThrow(RuntimeException::class, 'running performance cycle');
});

// ---- Withdraw from cycle -----------------------------------------------------------------------------------------

it('withdraws a non-finalized appraisal with a reason: reviews withdrawn, goal sheet and open goals cancelled, nothing deleted', function () {
    go29Policy('goal_sheet', ['plan_approval' => 'manager'], 'GO29PLAN');
    $cycle = go29Cycle('goal_setting');
    $goals = app(Goals::class);
    $goals->create(['level' => 'employee', 'employee_id' => $this->employee->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Revenue', 'weight' => 60, 'status' => 'draft'], [], $this->employee->user);
    $goals->create(['level' => 'employee', 'employee_id' => $this->employee->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Quality', 'weight' => 40, 'status' => 'draft'], [], $this->employee->user);
    $goals->submitPlan($this->employee, $cycle->id, $this->employee->user);
    $cycle = $this->appraisals->advance($cycle);
    $appraisal = go29Appraisal($cycle, $this->employee);
    $plan = GoalPlan::query()->where('employee_id', $this->employee->id)->sole();
    expect($plan->status)->toBe('pending');

    $withdrawn = $this->appraisals->withdraw($appraisal, 'Moved to the sales cycle', $this->hr);

    expect($withdrawn->status)->toBe('withdrawn')
        ->and($withdrawn->withdrawal_reason)->toBe('Moved to the sales cycle')
        ->and((int) $withdrawn->withdrawn_by)->toBe($this->hr->id)
        ->and($withdrawn->reviews()->pluck('status')->unique()->all())->toBe(['withdrawn'])
        ->and($plan->refresh()->status)->toBe('cancelled')
        ->and($plan->decision_note)->toContain('Moved to the sales cycle')
        ->and(Goal::query()->where('employee_id', $this->employee->id)->pluck('status')->unique()->all())->toBe(['cancelled'])
        ->and(Goal::query()->where('employee_id', $this->employee->id)->count())->toBe(2)
        ->and(Appraisal::query()->whereKey($appraisal->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->withoutTenancy()->where('entity_type', Appraisal::class)->where('entity_id', (string) $appraisal->id)->where('action', AuditAction::StatusChange->value)->get()
            ->filter(fn (AuditEvent $e) => ($e->metadata['event'] ?? null) === 'withdrawn_from_cycle' && ($e->metadata['goals_cancelled'] ?? null) === 2))->toHaveCount(1)
        ->and(go29Titles($this->employee->user, 'withdrawn from the performance cycle'))->toHaveCount(1)
        ->and(go29Titles($this->manager->user, 'withdrawn from the performance cycle'))->toHaveCount(1);

    // A withdrawn appraisal takes no more reviews, calibration or finalization, is not added again, and is out of the registers.
    $review = AppraisalReview::query()->where('appraisal_id', $appraisal->id)->where('type', 'self')->sole();
    expect(fn () => $this->appraisals->submitReview($review, [], [], 4, [], $this->employee->user))->toThrow(RuntimeException::class, 'withdrawn')
        ->and(fn () => $this->appraisals->calibrate($withdrawn, 3, 'x', $this->hr))->toThrow(RuntimeException::class, 'withdrawn')
        ->and(fn () => $this->appraisals->finalize($withdrawn, $this->hr, 3.0))->toThrow(RuntimeException::class, 'withdrawn')
        ->and(fn () => $this->appraisals->withdraw($withdrawn, 'Again', $this->hr))->toThrow(RuntimeException::class, 'Already withdrawn')
        ->and(fn () => $this->appraisals->addEmployee($cycle, $this->employee, $this->hr))->toThrow(RuntimeException::class, 'withdrawal is final')
        ->and(fn () => $withdrawn->update(['manager_summary' => 'x']))->toThrow(RuntimeException::class, 'withdrawn')
        ->and(fn () => $withdrawn->delete())->toThrow(RuntimeException::class)
        ->and(GoalSheetResource::getEloquentQuery()->pluck('appraisals.id')->all())->not->toContain($appraisal->id)
        ->and(app(PerformanceDistribution::class)->compare($cycle)['total'])->toBe(2);
});

it('never withdraws a finalized appraisal or one\'s own, and needs a reason and the right', function () {
    $cycle = go29Cycle('calibration');
    $peer = go29Appraisal($cycle, $this->peer);
    $this->appraisals->finalize($peer, $this->hr, 3.0);
    $mine = activeEmployee(null, ['performance.manage', 'performance.view']);
    $own = $this->appraisals->addEmployee($cycle, $mine, $this->hr)['appraisal'];

    expect(fn () => $this->appraisals->withdraw($peer->refresh(), 'Left', $this->hr))->toThrow(RuntimeException::class, 'finalized appraisal is kept')
        ->and(fn () => $this->appraisals->withdraw($own, 'Mine', $mine->user->fresh()))->toThrow(RuntimeException::class, 'your own appraisal')
        ->and(fn () => $this->appraisals->withdraw(go29Appraisal($cycle, $this->employee), '  ', $this->hr))->toThrow(RuntimeException::class, 'Say why')
        ->and(fn () => $this->appraisals->withdraw(go29Appraisal($cycle, $this->employee), 'Left', $this->manager->user))->toThrow(RuntimeException::class, 'cannot withdraw');

    // In bulk: one result each; the finalized one is refused, the others are withdrawn.
    $run = app(BulkRuns::class)->start($this->hr, 'appraisals', 'appraisals.withdraw', [$peer->id, go29Appraisal($cycle, $this->employee)->id], ['reason' => 'Restructuring']);
    $items = BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id');
    expect($items[$peer->id]->outcome)->toBe('refused')
        ->and($items[go29Appraisal($cycle, $this->employee)->id]->outcome)->toBe('done')
        ->and(go29Appraisal($cycle, $this->employee)->status)->toBe('withdrawn');

    // The cycle closes without the withdrawn appraisal (it is neither open nor waiting to be published) and locks it too.
    $this->appraisals->finalize(go29Appraisal($cycle, $this->manager), $this->hr, 3.0);
    $this->appraisals->withdraw($own, 'Not in scope', $this->hr);
    $closed = $this->appraisals->close($cycle->refresh(), $this->hr);
    expect($closed->status)->toBe('closed')->and(go29Appraisal($cycle, $this->employee)->locked_at)->not->toBeNull();
});

// ---- Window extensions -------------------------------------------------------------------------------------------

it('extends the goal-setting window for chosen people or everyone, as the goal-period checks read it', function () {
    $this->settings->set('performance.goals.edit_only_in_goal_stage', true);
    $cycle = go29Cycle('goal_setting');
    $goals = app(Goals::class);
    $check = fn (Employee $e) => fn () => $goals->assertGoalPeriodOpen($cycle->id, $e->user->fresh(), $e->id);

    $this->travelTo('2026-09-28 10:00:00');
    expect($check($this->employee))->toThrow(RuntimeException::class, 'goal-setting period');

    $result = $this->windows->extend($cycle, 'goal_setting', [$this->employee->id], '2026-10-02', 'Joined late', $this->hr);
    expect($result['extended'])->toBe(1)->and($result['skipped'])->toBe([]);
    $check($this->employee)();
    expect($check($this->peer))->toThrow(RuntimeException::class, 'goal-setting period');

    $this->windows->extend($cycle, 'goal_setting', null, '2026-10-01', 'Everyone needs more time', $this->hr);
    $check($this->peer)();
    expect($this->windows->stageEnd($cycle, 'goal_setting', $this->employee->id)->toDateString())->toBe('2026-10-02')
        ->and($this->windows->stageEnd($cycle, 'goal_setting', $this->peer->id)->toDateString())->toBe('2026-10-01')
        ->and($this->windows->stageEnd($cycle, 'goal_setting')->toDateString())->toBe('2026-10-01');

    // Once the cycle moves on to its reviews, goal setting stays open only for the people with time left.
    $cycle = $this->appraisals->advance($cycle);
    $check($this->employee)();
    $this->travelTo('2026-10-02 10:00:00');
    $check($this->employee)();
    expect($check($this->peer))->toThrow(RuntimeException::class, 'goal-setting period');
    $this->travelTo('2026-10-03 10:00:00');
    expect($check($this->employee))->toThrow(RuntimeException::class, 'goal-setting period');

    // The extensions are kept with their reasons and audited; they are never changed or deleted.
    $row = PerformanceWindowExtension::query()->whereNotNull('employee_id')->sole();
    expect($row->only(['stage', 'reason']))->toBe(['stage' => 'goal_setting', 'reason' => 'Joined late'])
        ->and($row->previous_ends_on->toDateString())->toBe('2026-09-25')
        ->and(AuditEvent::query()->withoutTenancy()->where('entity_type', PerformanceWindowExtension::class)->where('entity_id', (string) $row->id)->where('action', AuditAction::Create->value)->exists())->toBeTrue()
        ->and(fn () => $row->update(['ends_on' => '2026-12-31']))->toThrow(RuntimeException::class, 'extend again')
        ->and(fn () => $row->delete())->toThrow(RuntimeException::class, 'never deleted');
});

it('lets an extension move the performance policy\'s goal window too (HP-8i)', function () {
    go29Policy('performance', ['goal_window_days' => 10], 'GO29WIN');
    $cycle = go29Cycle('goal_setting', ['period_start' => '2026-09-01', 'period_end' => '2027-08-31']);
    $check = fn () => app(Goals::class)->assertGoalPeriodOpen($cycle->id, $this->employee->user->fresh(), $this->employee->id);

    expect($check)->toThrow(RuntimeException::class, 'goal window');
    $this->windows->extend($cycle, 'goal_setting', [$this->employee->id], '2026-09-30', 'Long leave', $this->hr);
    $check();
});

it('refuses extensions that are not later, in the past, of other stages or cycles, without a reason or the right', function () {
    $cycle = go29Cycle('self_review');
    $joiner = activeEmployee($this->manager);
    $draft = draftCycle(['code' => 'DRAFT', 'stages' => go29Stages()]);

    expect(fn () => $this->windows->extend($cycle, 'self_review', null, '2026-09-01', 'x', $this->hr))->toThrow(RuntimeException::class, 'cannot be in the past')
        ->and(fn () => $this->windows->extend($cycle, 'self_review', null, '2026-10-05', 'x', $this->hr))->toThrow(RuntimeException::class, 'Choose a date after 05 Oct 2026')
        ->and(fn () => $this->windows->extend($cycle, 'calibration', null, '2026-12-01', 'x', $this->hr))->toThrow(RuntimeException::class, 'goal-setting and review stages')
        ->and(fn () => $this->windows->extend($cycle, 'approver_review', null, '2026-12-01', 'x', $this->hr))->toThrow(RuntimeException::class, 'has no approver review stage')
        ->and(fn () => $this->windows->extend($cycle, 'self_review', null, '2026-12-01', ' ', $this->hr))->toThrow(RuntimeException::class, 'Say why')
        ->and(fn () => $this->windows->extend($cycle, 'self_review', null, '2026-12-01', 'x', $this->manager->user))->toThrow(RuntimeException::class, 'cannot extend')
        ->and(fn () => $this->windows->extend($draft, 'self_review', null, '2026-12-01', 'x', $this->hr))->toThrow(RuntimeException::class, 'running cycle')
        ->and(fn () => $this->windows->extend($cycle, 'self_review', [], '2026-12-01', 'x', $this->hr))->toThrow(RuntimeException::class, 'at least one employee');

    $this->windows->extend($cycle, 'self_review', [$this->peer->id], '2026-10-20', 'Travel', $this->hr);
    $this->appraisals->withdraw(go29Appraisal($cycle, $this->manager), 'Leaving', $this->hr);
    $result = $this->windows->extend($cycle, 'self_review', [$this->employee->id, $this->peer->id, $joiner->id, $this->manager->id], '2026-10-10', 'Audit week', $this->hr);

    expect($result['extended'])->toBe(1)
        ->and($result['skipped'])->toBe([$this->peer->id => 'Already has until 20 Oct 2026.', $joiner->id => 'Not in this cycle.', $this->manager->id => 'Withdrawn from this cycle.'])
        ->and(PerformanceWindows::summary($result))->toContain('1 extended until 10 Oct 2026')->toContain('3 skipped');
});

it('sends review reminders and shows due dates at the end of the window as extended for each employee', function () {
    $cycle = go29Cycle('manager_review');
    $this->travelTo('2026-10-13 08:00:00');
    $this->windows->extend($cycle, 'manager_review', [$this->employee->id], '2026-10-25', 'Manager on leave', $this->hr);
    $mine = go29Appraisal($cycle, $this->employee)->review('manager');
    $peers = go29Appraisal($cycle, $this->peer)->review('manager');

    $sent = app(PerformanceReminders::class)->tick();
    $reminded = PerformanceReminderLog::query()->where('reminder', 'review_due')->pluck('subject_id')->map(fn ($id) => (int) $id)->all();

    expect($sent['reviews'])->toBe(1)
        ->and($reminded)->toContain($peers->id)->not->toContain($mine->id)
        ->and(AppraisalReviewResource::dueOn($mine))->toBe('2026-10-25')
        ->and(AppraisalReviewResource::dueOn($peers))->toBe('2026-10-15')
        ->and(app(PerformanceReminders::class)->remindReviewer($mine))->toBeTrue()
        ->and(go29Titles($this->manager->user, '2026-10-25'))->not->toBeEmpty();
});

it('extends a 360 round for chosen employees: their reviewers may answer until then and the round stays open', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $template = FeedbackTemplate::create(['code' => 'F360', 'name' => '360 form', 'process' => 'feedback_360', 'questions' => [
        ['key' => 'teamwork', 'title' => 'Teamwork', 'type' => 'rating', 'scale_max' => 5, 'mandatory' => true],
    ]]);
    $policy = go29Policy('feedback_360', ['frequency' => 'quarterly', 'window_days' => 20, 'peer_feedback' => '1', 'peer_template_id' => $template->id], 'GO29360');
    $rounds = app(FeedbackRounds::class);
    $nominations = app(FeedbackNominations::class);
    $round = $rounds->launch($policy, [], $this->hr);
    $ask = function (Employee $about, Employee $reviewer) use ($round, $nominations) {
        $participant = FeedbackParticipant::query()->withoutGlobalScope(AccessScope::class)->where('feedback_round_id', $round->id)->where('employee_id', $about->id)->sole();
        $nominations->nominate($participant, ['peer' => [$reviewer->id]], [], $about->user);
        $nominations->submit($participant, $about->user);

        return FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->where('feedback_participant_id', $participant->id)->where('reviewer_employee_id', $reviewer->id)->sole();
    };
    $forEmployee = $ask($this->employee, $this->peer);
    $forPeer = $ask($this->peer, $this->employee);
    expect($round->window_closes_on->toDateString())->toBe('2026-10-20');

    $this->travelTo('2026-10-22 09:00:00');
    expect(fn () => $nominations->respond($forEmployee->refresh(), ['teamwork' => 4], $this->peer->user))->toThrow(RuntimeException::class, 'window is closed');
    $outsider = activeEmployee();
    $result = $this->windows->extendRound($round, [$this->employee->id, $outsider->id], '2026-10-30', 'Long leave', $this->hr);
    expect($result['extended'])->toBe(1)->and($result['skipped'])->toBe([$outsider->id => 'Not in this 360 round.']);

    expect($nominations->pendingFor($this->peer->user)->pluck('id')->all())->toBe([$forEmployee->id])
        ->and($nominations->pendingFor($this->employee->user))->toHaveCount(0)
        ->and(go29Tasks($this->peer, 'feedback_360')->first()->dueAt->toDateString())->toBe('2026-10-30');
    $nominations->respond($forEmployee->refresh(), ['teamwork' => 4], $this->peer->user);
    expect(fn () => $nominations->respond($forPeer->refresh(), ['teamwork' => 4], $this->employee->user))->toThrow(RuntimeException::class, 'window is closed');

    // The schedule keeps the round open while an extension runs, then closes it.
    $rounds->runSchedule();
    expect($round->refresh()->status)->toBe('open');
    $this->travelTo('2026-10-31 09:00:00');
    $rounds->runSchedule();
    expect($round->refresh()->status)->toBe('closed')
        // "Everyone" still moves the round's own window, as before.
        ->and(fn () => $this->windows->extendRound($round->refresh(), null, '2026-11-15', 'x', $this->hr))->toThrow(RuntimeException::class, 'open round');
});

// ---- GO-9 task kinds ---------------------------------------------------------------------------------------------

it('gives each reviewer a task for their pending review once its stage opens, due at the window as extended', function () {
    $cycle = go29Cycle('goal_setting');
    expect(go29Tasks($this->employee, 'review'))->toHaveCount(0);

    $cycle = $this->appraisals->advance($cycle); // self review
    $self = go29Tasks($this->employee, 'review');
    expect($self->pluck('title')->all())->toBe(['Self review for FY26 annual'])
        ->and($self->first()->dueAt->toDateString())->toBe('2026-10-05')
        ->and(go29Tasks($this->manager, 'review'))->toHaveCount(1); // the manager's own self review only

    $cycle = $this->appraisals->advance($cycle); // manager review
    $this->windows->extend($cycle, 'manager_review', [$this->employee->id], '2026-10-25', 'Leave', $this->hr);
    $managerTasks = go29Tasks($this->manager, 'review')->keyBy('title');
    $name = $this->employee->person->full_name;
    expect($managerTasks->keys()->all())->toContain("Manager review of {$name} (FY26 annual)")
        ->and($managerTasks["Manager review of {$name} (FY26 annual)"]->dueAt->toDateString())->toBe('2026-10-25');

    // A peer added by hand has a task too (no peer stage: open at once); a withdrawn appraisal gives none.
    $this->appraisals->addPeerReview(go29Appraisal($cycle, $this->peer), $this->employee);
    expect(go29Tasks($this->employee, 'review')->pluck('title')->all())->toContain('Peer review of '.$this->peer->person->full_name.' (FY26 annual)');
    $this->appraisals->withdraw(go29Appraisal($cycle, $this->peer), 'Left the team', $this->hr);
    expect(go29Tasks($this->employee, 'review')->pluck('title')->filter(fn ($t) => str_contains($t, 'Peer review'))->all())->toBe([])
        ->and(go29Tasks($this->manager, 'review')->pluck('title')->filter(fn ($t) => str_contains($t, $this->peer->person->full_name))->all())->toBe([]);

    // The unified inbox and My work pick the kind up; My work no longer repeats the reviews reminder.
    $this->actingAs($this->manager->user);
    $rows = app(UnifiedInbox::class)->rows($this->manager->user->fresh());
    $facets = app(UnifiedInbox::class)->facets($rows);
    expect($facets)->toHaveKey('task:performance.review')
        ->and($facets['task:performance.review']['label'])->toBe('Performance review');
    $work = app(WorkInbox::class)->for($this->manager->user->fresh());
    $all = collect($work)->only(['needs_attention', 'today', 'upcoming'])->flatten(1);
    expect($all->pluck('title')->all())->toContain("Manager review of {$name} (FY26 annual)")
        ->and($all->pluck('key')->all())->not->toContain('attention:reviews');
});

it('asks for the goal sheet while goal setting is open, from whoever fills it in', function () {
    $cycle = go29Cycle('goal_setting');
    $create = go29Tasks($this->employee, 'create_goal');
    expect($create->pluck('title')->all())->toBe(['Create your goals for FY26 annual'])
        ->and($create->first()->dueAt->toDateString())->toBe('2026-09-25')
        ->and(go29Tasks($this->manager, 'create_goal')->pluck('title')->all())->toBe(['Create your goals for FY26 annual']);

    $goal = app(Goals::class)->create(['level' => 'employee', 'employee_id' => $this->employee->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Revenue', 'weight' => 100, 'status' => 'draft'], [], $this->employee->user);
    expect(go29Tasks($this->employee, 'create_goal')->pluck('title')->all())->toBe(['Finish and submit your goal sheet for FY26 annual']);
    app(Goals::class)->submitPlan($this->employee, $cycle->id, $this->employee->user);
    expect(go29Tasks($this->employee, 'create_goal'))->toHaveCount(0);

    // Past goal setting there is no task, unless the window was extended for the person.
    $cycle = $this->appraisals->advance($cycle);
    expect(go29Tasks($this->peer, 'create_goal'))->toHaveCount(0);
    $this->windows->extend($cycle, 'goal_setting', [$this->peer->id], '2026-10-10', 'Joined late', $this->hr);
    $late = go29Tasks($this->peer, 'create_goal');
    expect($late->pluck('title')->all())->toBe(['Create your goals for FY26 annual'])->and($late->first()->dueAt->toDateString())->toBe('2026-10-10');

    // A withdrawn employee has no goal sheet to create.
    $this->appraisals->withdraw(go29Appraisal($cycle, $this->peer), 'Left', $this->hr);
    expect(go29Tasks($this->peer, 'create_goal'))->toHaveCount(0);
});

it('asks the reporting manager for the goal sheet when the system maps it and the manager fills it in first', function () {
    go29Policy('goal_sheet', ['goal_creation' => 'system', 'goal_sheet_owner' => 'manager'], 'GO29OWNER');
    go29Cycle('goal_setting');

    expect(go29Tasks($this->employee, 'create_goal'))->toHaveCount(0)
        ->and(go29Tasks($this->manager, 'create_goal')->pluck('title')->all())->toContain('Create the goal sheet of '.$this->employee->person->full_name.' (FY26 annual)');
});

it('turns the scheduled goal check-in prompts into tasks until the goals are checked in', function () {
    $cycle = go29Cycle('goal_setting');
    $goal = app(Goals::class)->create(['level' => 'employee', 'employee_id' => $this->employee->id, 'performance_cycle_id' => $cycle->id, 'title' => 'Revenue', 'weight' => 100], [], $this->hr);
    app(Goals::class)->create(['level' => 'company', 'title' => 'Grow ARR', 'performance_cycle_id' => $cycle->id], [], $this->hr);
    expect(go29Tasks($this->employee, 'goal_check_in'))->toHaveCount(0); // prompts off (the default)

    $this->settings->set('performance.checkin.employee.interval', 'weekly');
    $this->settings->set('performance.checkin.employee.day', 1); // Mondays
    $this->settings->set('performance.checkin.company.interval', 'weekly');
    $this->settings->set('performance.checkin.company.day', 1);
    $task = go29Tasks($this->employee, 'goal_check_in')->first();
    expect($task->title)->toBe('Check in on your goals (1)')
        ->and($task->dueAt->toDateString())->toBe('2026-09-27')
        ->and(go29Tasks($this->employee, 'goal_check_in_company'))->toHaveCount(0);
    $hrTasks = app(ExperienceTasks::class)->for($this->hr->fresh(), null)->filter(fn ($t) => $t->kind === 'goal_check_in_company');
    expect($hrTasks->pluck('title')->all())->toBe(['Check in on the company goals you own (1)']);

    app(Goals::class)->checkIn($goal, 40, 'Halfway', null, $this->employee->user);
    expect(go29Tasks($this->employee, 'goal_check_in'))->toHaveCount(0);
});

it('asks the employee to acknowledge a published rating they may see', function () {
    $cycle = go29Cycle('calibration');
    $appraisal = go29Appraisal($cycle, $this->employee);
    $this->appraisals->finalize($appraisal, $this->hr, 4.0);

    $task = go29Tasks($this->employee, 'rating_acknowledgement');
    expect($task->pluck('title')->all())->toBe(['Acknowledge your performance rating for FY26 annual'])
        ->and($task->first()->dueAt)->toBeNull()
        ->and($task->first()->title)->not->toContain('4')
        ->and(go29Tasks($this->manager, 'rating_acknowledgement'))->toHaveCount(0);

    // The performance policy may hide the final rating from the employee: then there is nothing to acknowledge in My tasks.
    go29Policy('performance', ['final_rating_visible_to' => 'all_except_employee'], 'GO29VIS');
    expect(go29Tasks($this->employee, 'rating_acknowledgement'))->toHaveCount(0);
    PolicyAssignmentRule::query()->where('policy_type', 'performance')->delete();
    app(PerformancePolicyRules::class)->forget();

    $this->appraisals->acknowledge($appraisal->refresh(), 'Thanks');
    expect(go29Tasks($this->employee, 'rating_acknowledgement'))->toHaveCount(0);
});

it('asks only the plan\'s manager to evaluate an improvement plan that reached its end date', function () {
    $plan = app(ImprovementPlans::class)->open($this->employee, $this->manager, 'Missed targets', [['objective' => 'Close 5 deals']], '2026-08-01', '2026-09-30', null, $this->hr);
    expect(go29Tasks($this->manager, 'pip_evaluation_due'))->toHaveCount(0);

    $this->travelTo('2026-09-30 10:00:00');
    $task = go29Tasks($this->manager, 'pip_evaluation_due')->first();
    expect($task->title)->toBe('Evaluate the improvement plan of '.$this->employee->person->full_name)
        ->and($task->title)->not->toContain('Missed targets')
        ->and($task->dueAt->toDateString())->toBe('2026-09-30')
        ->and(go29Tasks($this->employee, 'pip_evaluation_due'))->toHaveCount(0)
        ->and(app(ExperienceTasks::class)->for($this->hr->fresh(), null)->where('kind', 'pip_evaluation_due'))->toHaveCount(0);

    app(ImprovementPlans::class)->close($plan->refresh(), 'completed', 'Targets met', $this->hr);
    expect(go29Tasks($this->manager, 'pip_evaluation_due'))->toHaveCount(0);
});

// ---- Screens -----------------------------------------------------------------------------------------------------

it('offers the population actions on a running cycle and the withdraw action on an appraisal', function () {
    $cycle = go29Cycle('self_review');
    $joiner = activeEmployee($this->manager);

    Livewire::test(EditPerformanceCycle::class, ['record' => $cycle->getRouteKey()])
        ->assertActionVisible('addEmployees')->assertActionVisible('extendWindow')->assertActionVisible('withdrawEmployees')
        ->callAction('extendWindow', ['stage' => 'self_review', 'who' => 'people', 'employee_ids' => [$this->employee->id], 'ends_on' => '2026-10-12', 'reason' => 'Audit season'])
        ->assertHasNoActionErrors();
    expect($this->windows->stageEnd($cycle, 'self_review', $this->employee->id)->toDateString())->toBe('2026-10-12');

    Livewire::test(EditPerformanceCycle::class, ['record' => $cycle->getRouteKey()])
        ->callAction('addEmployees', ['who' => 'codes', 'codes' => $joiner->employee_code])->assertHasNoActionErrors();
    expect(Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $joiner->id)->exists())->toBeTrue();

    Livewire::test(EditPerformanceCycle::class, ['record' => $cycle->getRouteKey()])
        ->callAction('withdrawEmployees', ['who' => 'people', 'employee_ids' => [$joiner->id], 'reason' => 'Added by mistake'])->assertHasNoActionErrors();
    expect(go29Appraisal($cycle, $joiner)->status)->toBe('withdrawn');

    Livewire::test(WindowExtensionsRelationManager::class, ['ownerRecord' => $cycle, 'pageClass' => EditPerformanceCycle::class])
        ->assertCanSeeTableRecords(PerformanceWindowExtension::query()->get());

    $appraisal = go29Appraisal($cycle, $this->peer);
    Livewire::test(ViewAppraisal::class, ['record' => $appraisal->getRouteKey()])
        ->callAction('withdrawFromCycle', ['reason' => 'Moved'])->assertHasNoActionErrors();
    expect($appraisal->refresh()->status)->toBe('withdrawn');
    Livewire::test(ViewAppraisal::class, ['record' => $appraisal->getRouteKey()])->assertActionHidden('withdrawFromCycle');

    // The registers' header actions; managers and employees get none of them.
    Livewire::test(ListGoalPlans::class)->assertActionVisible('posPerfExtendGoal');
    Livewire::test(ListAppraisalReviews::class)->assertActionVisible('posPerfExtendReview');
    Livewire::test(ListAppraisals::class)->assertTableBulkActionExists('bulk_appraisals_withdraw');
    $this->actingAs($this->manager->user);
    Livewire::test(ViewAppraisal::class, ['record' => go29Appraisal($cycle, $this->employee)->getRouteKey()])->assertActionHidden('withdrawFromCycle');
});

it('extends a 360 round for chosen people from the round page', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $template = FeedbackTemplate::create(['code' => 'F360', 'name' => '360 form', 'process' => 'feedback_360', 'questions' => [['key' => 'q', 'title' => 'Q', 'type' => 'rating', 'scale_max' => 5, 'mandatory' => true]]]);
    $policy = go29Policy('feedback_360', ['frequency' => 'quarterly', 'window_days' => 20, 'peer_feedback' => '1', 'peer_template_id' => $template->id], 'GO29R');
    $round = app(FeedbackRounds::class)->launch($policy, [], $this->hr);

    Livewire::test(ViewFeedbackRound::class, ['record' => $round->getRouteKey()])
        ->callAction('extend', ['who' => 'people', 'employee_ids' => [$this->employee->id], 'closes_on' => '2026-10-28', 'reason' => 'Leave'])->assertHasNoActionErrors();
    expect($round->refresh()->window_closes_on->toDateString())->toBe('2026-10-20')
        ->and($this->windows->roundClosesOn($round, $this->employee->id)->toDateString())->toBe('2026-10-28');

    // Everyone (the default) moves the round's own window, as before.
    Livewire::test(ViewFeedbackRound::class, ['record' => $round->getRouteKey()])
        ->callAction('extend', ['closes_on' => '2026-10-24', 'reason' => 'Busy month'])->assertHasNoActionErrors();
    expect($round->refresh()->window_closes_on->toDateString())->toBe('2026-10-24');
});

it('keeps extensions and tasks inside their tenant', function () {
    $cycle = go29Cycle('self_review');
    $this->windows->extend($cycle, 'self_review', null, '2026-10-20', 'Busy', $this->hr);

    $other = provisionTenant();
    actAsTenant($other);
    expect(PerformanceWindowExtension::query()->count())->toBe(0)
        ->and(Appraisal::query()->count())->toBe(0);
    actAsTenant($this->tenant);
    expect(PerformanceWindowExtension::query()->count())->toBe(1);
});
