<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\AppraisalReview;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalPlan;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformanceLookup;
use App\Domain\Performance\Models\PerformanceReminderLog;
use App\Domain\Performance\Services\Appraisals;
use App\Domain\Performance\Services\GoalPlans;
use App\Domain\Performance\Services\Goals;
use App\Domain\Performance\Services\GoalSheets;
use App\Domain\Performance\Services\ImprovementPlans;
use App\Domain\Performance\Services\Initiatives;
use App\Domain\Performance\Services\PerformanceReminders;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\EngageDesk;
use App\Filament\Pages\PerformanceDesk;
use App\Filament\Resources\AppraisalReviews\AppraisalReviewResource;
use App\Filament\Resources\AppraisalReviews\Pages\ListAppraisalReviews;
use App\Filament\Resources\Appraisals\Pages\ListAppraisals;
use App\Filament\Resources\Appraisals\Pages\ViewAppraisal;
use App\Filament\Resources\Appraisals\RelationManagers\ReviewsRelationManager;
use App\Filament\Resources\GoalPlans\GoalPlanResource;
use App\Filament\Resources\GoalPlans\Pages\ListGoalPlans;
use App\Filament\Resources\Goals\Pages\ListGoals;
use App\Filament\Resources\GoalSheets\GoalSheetResource;
use App\Filament\Resources\GoalSheets\Pages\ListGoalSheets;
use App\Filament\Resources\ImprovementPlans\ImprovementPlanResource;
use App\Filament\Resources\ImprovementPlans\Pages\ManageImprovementPlans;
use App\Filament\Resources\Initiatives\Pages\ManageInitiatives;
use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Resources\Letters\Pages\ListLetters;
use App\Filament\Support\PerformanceRegisters;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Section;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/*
| HROne parity GO-1 (matrix 27: the Performance and Engage panels, goal sheets, goal requests, performance review requests
| with "Remind reviewers", HR "Reopen review" behind owner decision PER-1 / D-GO1, the appraisal created-date filter, PIP
| and PIP evaluation register depth, the "Goals and initiatives" drawer, performance letters as Letters views). Every
| default keeps today's behaviour; the new registers are read-only and open for performance HR only.
*/

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00'); // a Monday
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->manager = activeEmployee(null, ['performance.team', 'performance.review', 'performance.goals', 'performance.initiatives', 'task.view', 'task.act']);
    $this->employee = activeEmployee($this->manager, ['performance.goals', 'performance.review', 'performance.feedback', 'performance.initiatives', 'task.view']);
    $this->peer = activeEmployee($this->manager);
    $this->appraisals = app(Appraisals::class);
    $this->settings = app(SettingsRepository::class);
});

/** A launched cycle moved to $stage (goal setting → self review → manager review → calibration → final). */
function go1Cycle(string $stage = 'goal_setting', array $overrides = []): PerformanceCycle
{
    $appraisals = app(Appraisals::class);
    $cycle = $appraisals->launch(draftCycle($overrides));
    while ($cycle->current_stage !== $stage) {
        $cycle = $appraisals->advance($cycle);
    }

    return $cycle;
}

function go1Appraisal(PerformanceCycle $cycle, Employee $employee): Appraisal
{
    return Appraisal::query()->where('performance_cycle_id', $cycle->id)->where('employee_id', $employee->id)->firstOrFail();
}

/** The employee's self review and the manager's review, both submitted (the cycle ends at the manager review). */
function go1Reviewed(): array
{
    $appraisals = app(Appraisals::class);
    $cycle = go1Cycle('self_review');
    $employee = test()->employee;
    $appraisal = go1Appraisal($cycle, $employee);
    $appraisals->submitReview($appraisal->review('self'), [], array_fill_keys($cycle->competency_ids, 4), 4, ['strengths' => 'Self view'], $employee->user);
    $cycle = $appraisals->advance($cycle);
    $appraisal->refresh()->load('reviews');
    $appraisals->submitReview($appraisal->review('manager'), [], array_fill_keys($cycle->competency_ids, 3), 3, ['strengths' => 'Manager view', 'comments' => 'Solid year'], test()->manager->user);

    return [$cycle, $appraisal->refresh()->load('reviews')];
}

function go1GoalSheetPolicy(array $settings): void
{
    $policy = Policy::create(['type' => 'goal_sheet', 'name' => 'Goal sheet', 'code' => 'GO1_SHEET']);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'goal_sheet', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
}

// ---- Defaults ------------------------------------------------------------------------------------------------------

it('keeps today\'s behaviour by default: reopening is off and the employee\'s and manager\'s lists are unchanged', function () {
    [, $appraisal] = go1Reviewed();
    $manager = $appraisal->review('manager');

    expect($this->settings->get('performance.review.hr_reopen'))->toBeFalse()
        ->and(Appraisals::reopenEnabled())->toBeFalse()
        ->and(fn () => $this->appraisals->reopenReview($manager, 'Wrong ratings', $this->hr))->toThrow(RuntimeException::class, 'turned off')
        ->and($manager->refresh()->status)->toBe('submitted')
        ->and(AppraisalReviewResource::bulkOperations())->toBe(['review_requests.remind']);

    // HR sees no "Reopen" on the Reviews tab while the setting is off.
    Livewire::test(ReviewsRelationManager::class, ['ownerRecord' => $appraisal, 'pageClass' => ViewAppraisal::class])
        ->assertTableActionHidden('reopenReview', $manager);

    // The employee's own appraisal list: no new filters, header tools or actions menu.
    $this->actingAs($this->employee->user);
    $page = Livewire::test(ListAppraisals::class)->assertCanSeeTableRecords([$appraisal]);
    $filters = collect($page->instance()->getTable()->getFilters())->filter(fn ($f) => $f->isVisible())->keys()->all();
    expect($filters)->toBe(['performance_cycle_id', 'status'])
        ->and((fn () => $this->getHeaderActions())->call($page->instance()))->toBe([])
        ->and(PerformanceRegisters::actionsMenu()->isVisible())->toBeFalse()
        ->and(GoalSheetResource::canAccess())->toBeFalse()->and(GoalPlanResource::canAccess())->toBeFalse()->and(AppraisalReviewResource::canAccess())->toBeFalse();
    $this->get(AppraisalReviewResource::getUrl('index'))->assertForbidden();

    // The manager's improvement plan list keeps its own filters only.
    $this->actingAs($this->manager->user);
    $plans = Livewire::test(ManageImprovementPlans::class);
    expect(collect($plans->instance()->getTable()->getFilters())->filter(fn ($f) => $f->isVisible())->keys()->all())->toBe(['status']);
});

// ---- The desks -----------------------------------------------------------------------------------------------------

it('opens the performance desk for performance operators only, in HROne\'s panel order with views of existing registers', function () {
    go1Reviewed();

    $html = $this->get(PerformanceDesk::getUrl())->assertOk()
        ->assertSee('Goals')->assertSee('Continuous feedback')->assertSee('Performance improvement')->assertSee('Performance letters')
        ->assertSee(GoalSheetResource::getUrl('index'), false)
        ->assertSee(AppraisalReviewResource::getUrl('index', ['view' => 'pending']), false)
        ->assertSee(ImprovementPlanResource::getUrl('index', ['view' => 'evaluations']), false)
        ->assertSee(LetterResource::getUrl('index', ['view' => 'pip_outcome']), false)
        ->getContent();
    $sections = collect((new PerformanceDesk)->deskSections());
    expect($sections->pluck('label')->all())->toBe(['Goals', 'Reviews', 'Continuous feedback', 'Performance improvement', 'Performance letters', 'Reports', 'Performance setup'])
        // The manager's and the peer's self reviews and the peer's manager review are still to do.
        ->and(collect($sections->firstWhere('label', 'Reviews')['items'])->firstWhere('label', 'Performance review requests')['count'])->toBe(3);

    // A manager (team rights) and a read-only viewer get no desk; a PIP administrator gets one with what they can open.
    $this->actingAs($this->manager->user);
    expect(PerformanceDesk::canAccess())->toBeFalse();
    $this->get(PerformanceDesk::getUrl())->assertForbidden();
    $this->actingAs(tenantUser($this->tenant, ['performance.view']));
    expect(PerformanceDesk::canAccess())->toBeFalse();
    $this->actingAs(tenantUser($this->tenant, ['performance.view', 'performance.pip']));
    $labels = collect((new PerformanceDesk)->deskSections())->flatMap(fn (array $s) => array_column($s['items'], 'label'));
    expect(PerformanceDesk::canAccess())->toBeTrue()
        ->and($labels)->toContain('Goal sheets')->toContain('PIP evaluations')->not->toContain('Normalise and publish ratings');
});

it('opens the engage desk for engagement operators with the engage registers', function () {
    $this->get(EngageDesk::getUrl())->assertOk()->assertSee('HR handbook')->assertSee('Announcement')->assertSee('Survey')->assertSee('Recognition');
    expect(collect((new EngageDesk)->deskSections())->pluck('label')->all())->toContain('HR handbook', 'Announcement', 'Survey', 'Recognition', 'Engage setup');

    $this->actingAs($this->employee->user);
    expect(EngageDesk::canAccess())->toBeFalse();
    $this->actingAs(tenantUser($this->tenant, ['communication.view', 'communication.manage']));
    expect(EngageDesk::canAccess())->toBeTrue()
        ->and(collect((new EngageDesk)->deskSections())->flatMap(fn (array $s) => array_column($s['items'], 'label')))->toContain('Announcements')->not->toContain('Badges');
});

// ---- Goal sheets and goal requests ---------------------------------------------------------------------------------

it('lists goal sheets per employee and cycle with their status, the same under the status filter', function () {
    go1GoalSheetPolicy(['plan_approval' => 'manager']);
    $cycle = go1Cycle('goal_setting', ['settings' => ['required_goal_weight' => 100]]);
    $goals = app(Goals::class);
    $goal = fn (Employee $e, string $title, int $weight) => $goals->create(['employee_id' => $e->id, 'performance_cycle_id' => $cycle->id, 'status' => 'draft', 'title' => $title, 'weight' => $weight], [], $e->user);

    // The employee submits a sheet that the manager sends back; the peer's sheet waits; the manager has none yet.
    $goal($this->employee, 'Revenue', 60);
    $goal($this->employee, 'Hiring', 40);
    $goals->submitPlan($this->employee, $cycle->id, $this->employee->user);
    app(GoalPlans::class)->reject(GoalPlan::query()->where('employee_id', $this->employee->id)->firstOrFail(), 'Raise the revenue target', $this->manager->user);
    $goal($this->peer, 'Quality', 100);
    $goals->submitPlan($this->peer, $cycle->id, $this->peer->user);

    $rows = GoalSheetResource::getEloquentQuery()->where('performance_cycle_id', $cycle->id)->get()->keyBy('employee_id');
    expect(GoalSheets::statusFrom($rows[$this->employee->id]))->toBe('sent_back')
        ->and(GoalSheets::statusFrom($rows[$this->peer->id]))->toBe('pending')
        ->and(GoalSheets::statusFrom($rows[$this->manager->id]))->toBe('not_created')
        ->and((float) $rows[$this->employee->id]->getAttributes()['sheet_weight'])->toBe(100.0);

    $sheets = fn (string $status) => Livewire::withQueryParams(['view' => $status])->test(ListGoalSheets::class);
    $byEmployee = fn (Employee $e) => go1Appraisal($cycle, $e);
    $sheets('sent_back')->assertCanSeeTableRecords([$byEmployee($this->employee)])->assertCanNotSeeTableRecords([$byEmployee($this->peer), $byEmployee($this->manager)]);
    $sheets('pending')->assertCanSeeTableRecords([$byEmployee($this->peer)])->assertCanNotSeeTableRecords([$byEmployee($this->employee)]);
    $sheets('not_created')->assertCanSeeTableRecords([$byEmployee($this->manager)])->assertCanNotSeeTableRecords([$byEmployee($this->peer)]);

    // The employee changes and resubmits; the manager approves: draft while changed, then active.
    $draft = Goal::query()->where('employee_id', $this->employee->id)->where('title', 'Revenue')->firstOrFail();
    expect(app(GoalSheets::class)->sheet($byEmployee($this->employee))['status'])->toBe('sent_back');
    $goals->submitPlan($this->employee, $cycle->id, $this->employee->user);
    app(GoalPlans::class)->approve(GoalPlan::query()->where('employee_id', $this->employee->id)->where('status', 'pending')->firstOrFail(), null, $this->manager->user);
    $sheets('active')->assertCanSeeTableRecords([$byEmployee($this->employee)]);
    $goals->create(['employee_id' => $this->employee->id, 'performance_cycle_id' => $cycle->id, 'status' => 'draft', 'title' => 'Learning', 'weight' => 0], [], $this->hr);
    $sheets('draft')->assertCanSeeTableRecords([$byEmployee($this->employee)]);
    expect($draft->refresh()->status)->toBe('active');
});

it('shows a goal sheet with its KPIs, competencies and their weightage, and every request in order', function () {
    go1GoalSheetPolicy(['plan_approval' => 'manager']);
    $cycle = go1Cycle('goal_setting');
    app(Goals::class)->create(['employee_id' => $this->employee->id, 'performance_cycle_id' => $cycle->id, 'status' => 'draft', 'title' => 'Revenue', 'weight' => 100],
        [['title' => 'New logos', 'weight' => 100, 'target_value' => 12, 'measure_type' => 'numeric']], $this->employee->user);
    app(Goals::class)->submitPlan($this->employee, $cycle->id, $this->employee->user);
    $appraisal = go1Appraisal($cycle, $this->employee);

    $sheet = app(GoalSheets::class)->sheet($appraisal);
    expect($sheet['status'])->toBe('pending')
        ->and($sheet['goals']->pluck('title')->all())->toBe(['Revenue'])
        ->and($sheet['total_weight'])->toBe(100.0)
        ->and(count($sheet['competencies']))->toBe(count($cycle->competency_ids))
        ->and($sheet['competency_share'])->toBe(30.0)
        ->and($sheet['plans'])->toHaveCount(1);
    $lines = PerformanceRegisters::goalLines($sheet['goals']);
    expect($lines[0])->toContain('Revenue · weight 100%')->and($lines[1])->toContain('↳ New logos');

    // The sheet view renders in the register; "Goals" opens the Goals list filtered to the sheet.
    $schema = GoalSheetResource::sheetSchema($appraisal);
    expect(collect($schema)->map(fn (Section $s) => $s->getHeading())->all())->toBe(['Goals', 'Competencies', 'Goal requests (versions)']);
    Livewire::test(ListGoalSheets::class)->assertCanSeeTableRecords([$appraisal])->mountTableAction('viewSheet', $appraisal)->assertSet('mountedActions.0.name', 'viewSheet');
    $other = app(Goals::class)->create(['employee_id' => $this->peer->id, 'performance_cycle_id' => $cycle->id, 'status' => 'draft', 'title' => 'Quality', 'weight' => 100], [], $this->hr);
    Livewire::withQueryParams(['filters' => ['employee_id' => ['value' => $this->employee->id], 'performance_cycle_id' => ['value' => $cycle->id]]])->test(ListGoals::class)
        ->assertCanSeeTableRecords(Goal::query()->where('employee_id', $this->employee->id)->get())->assertCanNotSeeTableRecords([$other]);
});

it('lists goal requests with version, route, decision and approval trail, read-only and for HR only', function () {
    go1GoalSheetPolicy(['plan_approval' => 'manager']);
    $cycle = go1Cycle('goal_setting');
    $goals = app(Goals::class);
    $goals->create(['employee_id' => $this->employee->id, 'performance_cycle_id' => $cycle->id, 'status' => 'draft', 'title' => 'Revenue', 'weight' => 100], [], $this->employee->user);
    $goals->submitPlan($this->employee, $cycle->id, $this->employee->user);
    $first = GoalPlan::query()->firstOrFail();
    app(GoalPlans::class)->reject($first, 'Too vague', $this->manager->user);
    $goals->submitPlan($this->employee, $cycle->id, $this->employee->user);
    $second = GoalPlan::query()->where('status', 'pending')->firstOrFail();

    $rows = GoalPlanResource::getEloquentQuery()->orderBy('id')->get();
    expect($rows->map(fn (GoalPlan $p) => (int) $p->getAttributes()['request_version'])->all())->toBe([1, 2])
        ->and(PerformanceRegisters::approvalLabel($second, true))->toBe('Approval Center')
        ->and(PerformanceRegisters::approvalLabel($first->refresh(), false))->toBeNull();

    Livewire::withQueryParams(['view' => 'pending'])->test(ListGoalPlans::class)->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first]);
    Livewire::withQueryParams(['view' => 'sent_back'])->test(ListGoalPlans::class)->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
    Livewire::withQueryParams([])->test(ListGoalPlans::class)->filterTable('request_dates', ['from' => '2026-09-21', 'until' => '2026-09-21'])->assertCanSeeTableRecords([$first, $second])
        ->searchTable($this->employee->employee_code)->assertCanSeeTableRecords([$first, $second]);
    $this->get(GoalPlanResource::getUrl('view', ['record' => $first]))->assertOk()->assertSee('Too vague')->assertSee('Sent back');
    expect(GoalPlanResource::canCreate())->toBeFalse()->and(GoalPlanResource::canEdit($first))->toBeFalse();

    $this->actingAs($this->manager->user);
    $this->get(GoalPlanResource::getUrl('index'))->assertForbidden();
});

// ---- Review requests, reminders, reopen --------------------------------------------------------------------------

it('lists review requests of appraisals in scope with level, reviewer, status and due date', function () {
    [$cycle, $appraisal] = go1Reviewed();
    $peerAppraisal = go1Appraisal($cycle, $this->peer);
    $pending = $peerAppraisal->review('manager');
    $done = $appraisal->review('manager');

    Livewire::withQueryParams(['view' => 'pending'])->test(ListAppraisalReviews::class)->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$done]);
    Livewire::withQueryParams(['view' => 'manager_pending'])->test(ListAppraisalReviews::class)->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$peerAppraisal->review('self'), $done]);
    Livewire::withQueryParams([])->test(ListAppraisalReviews::class)->filterTable('performance_cycle_id', $cycle->id)->filterTable('created_dates', ['from' => '2026-09-21'])
        ->assertCanSeeTableRecords([$pending, $done])->searchTable($this->peer->employee_code)->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$done]);
    expect(AppraisalReviewResource::dueOn($pending))->toBe(collect($cycle->stages)->firstWhere('key', 'manager_review')['ends_on']);

    // HR scoped to another company sees none of them; another tenant's reviews never show.
    $elsewhere = Company::factory()->create();
    $scoped = tenantUser($this->tenant, ['performance.view', 'performance.manage']);
    app(AccessScopes::class)->assign($scoped, ['company' => [$elsewhere->id]], 'Other company only');
    $this->actingAs($scoped);
    Livewire::withQueryParams([])->test(ListAppraisalReviews::class)->assertCanNotSeeTableRecords([$pending, $done]);
    expect(AppraisalReviewResource::getEloquentQuery()->count())->toBe(0);
});

it('reminds reviewers of pending reviews once a day, alone or in bulk, as the scheduled reminder does', function () {
    [$cycle, $appraisal] = go1Reviewed();
    $pending = go1Appraisal($cycle, $this->peer)->review('manager');
    $done = $appraisal->review('manager');
    $titles = fn () => $this->manager->user->notifications()->get()->pluck('data.title')->filter(fn ($t) => str_starts_with((string) $t, 'Reminder: review due'))->values();

    Livewire::test(ListAppraisalReviews::class)->assertTableActionVisible('remindReviewer', $pending)->assertTableActionHidden('remindReviewer', $done)
        ->callTableAction('remindReviewer', $pending);
    expect($titles())->toHaveCount(1)
        ->and(PerformanceReminderLog::query()->where('reminder', 'review_due')->where('subject_id', $pending->id)->count())->toBe(1)
        ->and(app(PerformanceReminders::class)->remindReviewer($pending->refresh()))->toBeFalse()
        ->and(fn () => app(PerformanceReminders::class)->remindReviewer($done))->toThrow(RuntimeException::class, 'Only a pending review');

    // Bulk: the pending review is already reminded today, the submitted one is refused; tomorrow it goes again.
    $this->travelTo('2026-09-22 09:00:00');
    $run = app(BulkRuns::class)->start($this->hr, 'review_requests', 'review_requests.remind', [$pending->id, $done->id]);
    $items = BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id');
    expect($run->refresh()->status)->toBe('completed')
        ->and($items[$pending->id]->outcome)->toBe('done')
        ->and($items[$done->id]->outcome)->toBe('refused')->and($items[$done->id]->message)->toContain('Only a pending review')
        ->and($titles())->toHaveCount(2);

    // Only HR with the right: a manager may neither remind nor run the operation.
    $this->actingAs($this->manager->user);
    expect(app(BulkOperations::class)->allows($this->manager->user, 'review_requests.remind'))->toBeFalse()
        ->and(PerformanceRegisters::mayRemind($pending))->toBeFalse();
});

it('reopens a submitted review only when switched on: reason, before finalization, audited, rescored, reviewer told', function () {
    [$cycle, $appraisal] = go1Reviewed();
    $review = $appraisal->review('manager');
    $before = $appraisal->computed_rating;
    $this->settings->set('performance.review.hr_reopen', true, 'Owner decision PER-1');

    expect(fn () => $this->appraisals->reopenReview($review, '  ', $this->hr))->toThrow(RuntimeException::class, 'Say why')
        ->and(fn () => $this->appraisals->reopenReview($appraisal->review('self'), 'x', tenantUser($this->tenant, ['performance.view'])))->toThrow(RuntimeException::class, 'cannot reopen reviews');

    Livewire::test(ReviewsRelationManager::class, ['ownerRecord' => $appraisal, 'pageClass' => ViewAppraisal::class])
        ->assertTableActionVisible('reopenReview', $review)
        ->callTableAction('reopenReview', $review, ['reason' => 'Ratings entered against the wrong goals']);

    $review->refresh();
    expect($review->status)->toBe('pending')->and($review->submitted_at)->toBeNull()->and($review->overall_rating)->toBeNull()
        ->and($review->strengths)->toBeNull()->and($review->ratings()->count())->toBe(0)
        ->and($appraisal->refresh()->computed_rating)->not->toBe($before);

    // The earlier submission stays in the audit trail: the review's own fields and each rating (comments as sensitive).
    $events = AuditEvent::query()->withoutTenancy()->where('entity_type', AppraisalReview::class)->where('entity_id', (string) $review->id)->with('fieldChanges')->get();
    $reopen = $events->first(fn (AuditEvent $e) => ($e->metadata['event'] ?? null) === 'review_reopened');
    $update = $events->first(fn (AuditEvent $e) => $e->fieldChanges->contains('field', 'overall_rating') && $e->fieldChanges->firstWhere('field', 'status')?->after === 'pending');
    expect($reopen)->not->toBeNull()->and($reopen->reason)->toBe('Ratings entered against the wrong goals')->and($reopen->actor_id)->toBe($this->hr->id)
        ->and($reopen->fieldChanges->pluck('field')->filter(fn ($f) => str_starts_with($f, 'rating · competency #'))->count())->toBe(count($cycle->competency_ids))
        ->and($update)->not->toBeNull()->and((float) $update->fieldChanges->firstWhere('field', 'overall_rating')->before)->toBe(3.0)
        ->and($update->fieldChanges->firstWhere('field', 'strengths')->is_sensitive)->toBeTrue();

    // The reviewer is told, with the reason, and submits again (HR does not write it).
    expect($this->manager->user->notifications()->get()->pluck('data.title')->filter(fn ($t) => str_contains((string) $t, 'was reopened by HR: Ratings entered against the wrong goals')))->toHaveCount(1);
    $this->appraisals->submitReview($review->refresh(), [], array_fill_keys($cycle->competency_ids, 4), 4, [], $this->manager->user);
    expect($review->refresh()->status)->toBe('submitted');

    // Never one's own review or a review of one's own appraisal; never after finalization; never twice.
    $selfHr = activeEmployee(null, ['performance.view', 'performance.manage']);
    expect(fn () => $this->appraisals->reopenReview($appraisal->review('self'), 'Mine', $this->employee->user))->toThrow(RuntimeException::class, 'cannot reopen reviews')
        ->and(fn () => $this->appraisals->reopenReview($appraisal->review('self')->refresh(), 'x', $selfHr->user))->not->toThrow(RuntimeException::class)
        ->and(fn () => $this->appraisals->reopenReview($appraisal->review('self')->refresh(), 'again', $this->hr))->toThrow(RuntimeException::class, 'Only a submitted review');
    $this->appraisals->advance($cycle->refresh()); // calibration
    $this->appraisals->finalize($appraisal->refresh(), $this->hr, 4.0);
    expect(fn () => $this->appraisals->reopenReview($review->refresh(), 'Too late', $this->hr))->toThrow(RuntimeException::class, 'finalized');
});

it('refuses HR reopening their own review or a review of their own appraisal, and peers\' reviews', function () {
    $this->settings->set('performance.review.hr_reopen', true, 'Owner decision PER-1');
    $hrEmployee = activeEmployee(null, ['performance.view', 'performance.manage', 'performance.review', 'performance.team']);
    $report = activeEmployee($hrEmployee);
    $cycle = go1Cycle('self_review');
    $own = go1Appraisal($cycle, $hrEmployee);
    $this->appraisals->submitReview($own->review('self'), [], array_fill_keys($cycle->competency_ids, 4), 4, [], $hrEmployee->user);
    $cycle = $this->appraisals->advance($cycle);
    $reportAppraisal = go1Appraisal($cycle, $report);
    $this->appraisals->submitReview($reportAppraisal->review('manager'), [], array_fill_keys($cycle->competency_ids, 3), 3, [], $hrEmployee->user);
    $peer = $this->appraisals->addPeerReview($reportAppraisal->refresh(), $this->peer);
    $this->appraisals->submitReview($peer, [], array_fill_keys($cycle->competency_ids, 3), 3, [], $this->peer->user);

    expect(fn () => $this->appraisals->reopenReview($own->review('self'), 'x', $hrEmployee->user))->toThrow(RuntimeException::class, 'your own')
        ->and(fn () => $this->appraisals->reopenReview($reportAppraisal->review('manager'), 'x', $hrEmployee->user))->toThrow(RuntimeException::class, 'your own')
        ->and(fn () => $this->appraisals->reopenReview($peer->refresh(), 'x', $this->hr))->toThrow(RuntimeException::class, 'Only self, manager and approver');
    $this->actingAs($hrEmployee->user);
    expect(PerformanceRegisters::mayReopen($reportAppraisal->review('manager')))->toBeFalse();
});

it('reopens reviews in bulk only while the setting is on, each through the service with one reason', function () {
    [$cycle, $appraisal] = go1Reviewed();
    $done = $appraisal->review('manager');
    $pending = go1Appraisal($cycle, $this->peer)->review('manager');
    expect(fn () => app(BulkRuns::class)->start($this->hr, 'review_requests', 'review_requests.reopen', [$done->id], ['reason' => 'x']))->toThrow(RuntimeException::class, 'turned off');

    $this->settings->set('performance.review.hr_reopen', true, 'Owner decision PER-1');
    expect(AppraisalReviewResource::bulkOperations())->toBe(['review_requests.remind', 'review_requests.reopen']);
    $run = app(BulkRuns::class)->start($this->hr, 'review_requests', 'review_requests.reopen', [$done->id, $pending->id], ['reason' => 'Calibration found errors']);
    $items = BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id');
    expect($items[$done->id]->outcome)->toBe('done')->and($items[$pending->id]->outcome)->toBe('refused')
        ->and($items[$pending->id]->message)->toContain('Only a submitted review')
        ->and($done->refresh()->status)->toBe('pending')
        ->and(AuditEvent::query()->withoutTenancy()->where('entity_type', AppraisalReview::class)->where('entity_id', (string) $done->id)->where('reason', 'Calibration found errors')->where('operation_id', $run->refresh()->operation_id)->exists())->toBeTrue();
});

// ---- Appraisals, PIP, initiatives, letters ---------------------------------------------------------------------------

it('filters appraisals by created date and employee for HR, with views and the actions menu', function () {
    $older = go1Cycle('goal_setting', ['code' => 'FY25', 'name' => 'FY25 annual']);
    $this->travelTo('2026-10-05 09:00:00');
    $newer = go1Cycle('goal_setting', ['code' => 'FY26B', 'name' => 'FY26 second']);
    $a = go1Appraisal($older, $this->employee);
    $b = go1Appraisal($newer, $this->employee);

    $page = Livewire::test(ListAppraisals::class)->filterTable('created_dates', ['from' => '2026-10-01'])->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a])
        ->resetTableFilters()->filterTable('employee_codes', ['codes' => $this->peer->employee_code])->assertCanNotSeeTableRecords([$a, $b]);
    expect(collect((fn () => $this->getHeaderActions())->call($page->instance()))->map(fn ($a) => $a->getLabel())->all())->toBe(['Views', 'Report', 'Performance actions'])
        ->and(collect(PerformanceRegisters::actionsMenu()->getActions())->filter(fn ($a) => $a->isVisible())->map(fn ($a) => $a->getLabel())->values()->all())
        ->toBe(['Initiate goal or review cycle', 'Create company goal', 'Normalise and publish ratings', 'Initiate 360 feedback', 'Add PIP', 'Remind reviewers']);
});

it('gives the PIP register request, approval and end-date filters, requested / approved columns and the PIP evaluations view', function () {
    $plans = app(ImprovementPlans::class);
    $open = $plans->open($this->employee, $this->manager->id, 'Missed targets', [['objective' => 'Close 5 deals', 'measure' => 'CRM']], '2026-08-01', '2026-09-15', null, $this->hr);
    $this->travelTo('2026-09-25 10:00:00');
    $later = $plans->open($this->peer, $this->manager->id, 'Quality', [['objective' => 'Fewer defects', 'measure' => 'QA']], '2026-09-25', '2026-11-25', null, $this->hr);
    $plans->close($later->refresh(), 'unsuccessful', 'No change', $this->hr);

    // The first plan ended on 15 September without an outcome: its evaluation is due.
    Livewire::withQueryParams(['view' => 'evaluation_due'])->test(ManageImprovementPlans::class)->assertCanSeeTableRecords([$open])->assertCanNotSeeTableRecords([$later]);
    Livewire::withQueryParams(['view' => 'evaluations'])->test(ManageImprovementPlans::class)->assertCanSeeTableRecords([$open, $later]);
    Livewire::withQueryParams([])->test(ManageImprovementPlans::class)->filterTable('request_dates', ['from' => '2026-09-22'])->assertCanSeeTableRecords([$later])->assertCanNotSeeTableRecords([$open])
        ->resetTableFilters()->filterTable('end_dates', ['until' => '2026-09-30'])->assertCanSeeTableRecords([$open])->assertCanNotSeeTableRecords([$later])
        ->resetTableFilters()->filterTable('evaluation', ['not_improved'])->assertCanSeeTableRecords([$later])->assertCanNotSeeTableRecords([$open])
        ->assertTableColumnVisible('created_at')->assertTableColumnVisible('approved_at');
    expect(ImprovementPlan::query()->whereKey($open->id)->value('created_by'))->toBe($this->hr->id);
});

it('opens one employee\'s goals and initiatives of a cycle from the initiatives register, for those who may see the goals', function () {
    $cycle = go1Cycle('goal_setting');
    $goal = app(Goals::class)->create(['employee_id' => $this->employee->id, 'performance_cycle_id' => $cycle->id, 'status' => 'draft', 'title' => 'Revenue', 'weight' => 100], [], $this->employee->user);
    $type = PerformanceLookup::query()->ofKind('initiative_type')->where('status', 'active')->value('id');
    $linked = app(Initiatives::class)->create($this->employee, ['title' => 'Shadow a senior AE', 'initiative_type_id' => $type, 'goal_id' => $goal->id, 'due_date' => '2027-05-30'], $this->employee->user);
    $outside = app(Initiatives::class)->create($this->employee, ['title' => 'Old course', 'initiative_type_id' => $type, 'due_date' => '2025-01-15'], $this->employee->user);

    $drawer = app(GoalSheets::class)->goalsAndInitiatives($this->hr, $this->employee, $cycle);
    expect($drawer['goals']->pluck('title')->all())->toBe(['Revenue'])
        ->and($drawer['initiatives']->pluck('id')->all())->toBe([$linked->id])
        ->and(PerformanceRegisters::initiativeLines($drawer['initiatives'])[0])->toContain('Shadow a senior AE')->toContain('goal: Revenue')
        ->and(app(GoalSheets::class)->goalsAndInitiatives($this->hr, $this->employee, null)['initiatives']->pluck('id')->sort()->values()->all())->toBe([$linked->id, $outside->id]);

    Livewire::test(ManageInitiatives::class)->assertTableActionVisible('goalsAndInitiatives', $linked)
        ->mountTableAction('goalsAndInitiatives', $linked)->assertSet('mountedActions.0.name', 'goalsAndInitiatives')->assertSet('mountedActions.0.data.cycle_id', $cycle->id);

    // The manager sees the report's goals; a peer asked for nothing sees neither the goals nor the drawer.
    expect(GoalSheets::mayViewGoalsOf($this->manager->user, $this->employee))->toBeTrue()
        ->and(GoalSheets::mayViewGoalsOf($this->peer->user, $this->employee))->toBeFalse()
        ->and(app(GoalSheets::class)->goalsAndInitiatives($this->peer->user, $this->employee, $cycle))->toMatchArray(['goals' => null])
        ->and(app(GoalSheets::class)->goalsAndInitiatives($this->peer->user, $this->employee, $cycle)['initiatives'])->toHaveCount(0);
});

it('shows performance, PIP and PIP evaluation letters as views of the Letters register', function () {
    $views = ListLetters::presetViews();
    expect($views['performance']['filters'])->toBe(['type' => ['value' => 'performance']])
        ->and($views['pip']['label'])->toBe('PIP letters')
        ->and($views['pip_outcome']['label'])->toBe('PIP evaluation letters')
        // The workforce views are still there.
        ->and($views)->toHaveKeys(['confirmation', 'relieving', 'pending']);
    Livewire::withQueryParams(['view' => 'pip'])->test(ListLetters::class)->assertOk()->assertSet('tableFilters.type.value', 'pip');
});

it('renders the new registers and keeps them tenant-isolated', function () {
    [$cycle] = go1Reviewed();
    foreach ([GoalSheetResource::getUrl('index'), GoalPlanResource::getUrl('index'), AppraisalReviewResource::getUrl('index'), PerformanceDesk::getUrl(), EngageDesk::getUrl()] as $url) {
        $this->get($url)->assertOk();
    }

    $other = provisionTenant();
    actAsTenant($other);
    $otherHr = tenantUser($other, ['*']);
    $this->actingAs($otherHr);
    expect(AppraisalReviewResource::getEloquentQuery()->count())->toBe(0)
        ->and(GoalSheetResource::getEloquentQuery()->count())->toBe(0)
        ->and(GoalPlanResource::getEloquentQuery()->count())->toBe(0);
});
